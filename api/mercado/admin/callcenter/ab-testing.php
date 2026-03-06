<?php
/**
 * /api/mercado/admin/callcenter/ab-testing.php
 *
 * A/B Testing Management — Admin API
 *
 * GET actions:
 *   ?action=list             — List all tests with status, variants, sample sizes, conversion rates
 *   ?action=detail&id=X      — Full test details with per-variant metrics
 *   ?action=results&id=X     — Statistical analysis: conversion rate per variant, confidence intervals, winner
 *
 * POST actions:
 *   action=create            — Create new A/B test
 *   action=start&id=X        — Start running test
 *   action=pause&id=X        — Pause test
 *   action=complete&id=X     — End test, calculate winner
 *   action=assign            — Assign customer to variant (called by bot)
 *   action=record_result     — Record conversion result
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';

setCorsHeaders();

/**
 * Get active A/B test config for a customer phone + channel.
 * Uses consistent hash-based assignment so same phone always gets same variant.
 */
function getActiveABConfig(PDO $db, string $phone, string $channel): ?array
{
    $stmt = $db->prepare("
        SELECT id, name, test_type, variants
        FROM om_ab_tests
        WHERE status = 'running'
          AND (channel = ? OR channel = 'both')
        ORDER BY started_at DESC
    ");
    $stmt->execute([$channel]);
    $tests = $stmt->fetchAll();

    if (empty($tests)) {
        return null;
    }

    $configs = [];
    foreach ($tests as $test) {
        $variants = json_decode($test['variants'], true);
        if (empty($variants)) {
            continue;
        }

        // Check existing assignment
        $assignStmt = $db->prepare("
            SELECT variant_id FROM om_ab_assignments
            WHERE test_id = ? AND customer_phone = ?
            ORDER BY assigned_at DESC LIMIT 1
        ");
        $assignStmt->execute([(int)$test['id'], $phone]);
        $existing = $assignStmt->fetchColumn();

        if ($existing) {
            $variantId = $existing;
        } else {
            // Consistent hash: same phone always gets same variant
            $hash = crc32($phone . ':' . $test['id']);
            $totalWeight = array_sum(array_column($variants, 'weight'));
            if ($totalWeight <= 0) {
                $totalWeight = count($variants);
            }
            $pick = abs($hash) % $totalWeight;
            $cumulative = 0;
            $variantId = $variants[0]['id'];
            foreach ($variants as $v) {
                $cumulative += ($v['weight'] ?? 1);
                if ($pick < $cumulative) {
                    $variantId = $v['id'];
                    break;
                }
            }
        }

        // Find the variant config
        foreach ($variants as $v) {
            if ($v['id'] === $variantId) {
                $configs[] = [
                    'test_id'   => (int)$test['id'],
                    'test_type' => $test['test_type'],
                    'variant'   => $v,
                ];
                break;
            }
        }
    }

    return empty($configs) ? null : $configs;
}

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
            response(false, null, "Informe action: list, detail, results", 400);
        }

        // ── List all tests ──
        if ($action === 'list') {
            $status = trim($_GET['status'] ?? '');
            $conditions = [];
            $params = [];

            if ($status !== '') {
                $conditions[] = "t.status = ?";
                $params[] = $status;
            }

            $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

            $stmt = $db->prepare("
                SELECT t.*,
                       (SELECT COUNT(*) FROM om_ab_assignments a WHERE a.test_id = t.id) AS total_assignments,
                       (SELECT COUNT(*) FROM om_ab_assignments a WHERE a.test_id = t.id AND a.converted = TRUE) AS total_conversions
                FROM om_ab_tests t
                {$where}
                ORDER BY t.created_at DESC
            ");
            $stmt->execute($params);
            $tests = $stmt->fetchAll();

            foreach ($tests as &$test) {
                $test['id'] = (int)$test['id'];
                $test['min_sample_size'] = (int)$test['min_sample_size'];
                $test['variants'] = json_decode($test['variants'], true) ?: [];
                $test['metrics'] = json_decode($test['metrics'], true) ?: [];
                $test['total_assignments'] = (int)$test['total_assignments'];
                $test['total_conversions'] = (int)$test['total_conversions'];
                $test['conversion_rate'] = $test['total_assignments'] > 0
                    ? round(($test['total_conversions'] / $test['total_assignments']) * 100, 2)
                    : 0;
                $test['confidence_level'] = $test['confidence_level'] !== null ? (float)$test['confidence_level'] : null;
            }
            unset($test);

            response(true, ['tests' => $tests]);
        }

        // ── Test detail ──
        if ($action === 'detail') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                response(false, null, "Informe id do teste", 400);
            }

            $stmt = $db->prepare("SELECT * FROM om_ab_tests WHERE id = ?");
            $stmt->execute([$id]);
            $test = $stmt->fetch();

            if (!$test) {
                response(false, null, "Teste nao encontrado", 404);
            }

            $test['id'] = (int)$test['id'];
            $test['variants'] = json_decode($test['variants'], true) ?: [];
            $test['metrics'] = json_decode($test['metrics'], true) ?: [];
            $test['min_sample_size'] = (int)$test['min_sample_size'];
            $test['confidence_level'] = $test['confidence_level'] !== null ? (float)$test['confidence_level'] : null;

            // Per-variant stats
            $variantStats = [];
            foreach ($test['variants'] as $v) {
                $vStmt = $db->prepare("
                    SELECT
                        COUNT(*) AS assignments,
                        COUNT(*) FILTER (WHERE converted = TRUE) AS conversions,
                        COALESCE(SUM(order_value) FILTER (WHERE converted = TRUE), 0) AS revenue,
                        COALESCE(AVG(quality_score) FILTER (WHERE quality_score > 0), 0) AS avg_quality,
                        COALESCE(AVG(order_value) FILTER (WHERE converted = TRUE), 0) AS avg_order_value
                    FROM om_ab_assignments
                    WHERE test_id = ? AND variant_id = ?
                ");
                $vStmt->execute([$id, $v['id']]);
                $stats = $vStmt->fetch();

                $variantStats[$v['id']] = [
                    'variant_id'      => $v['id'],
                    'variant_name'    => $v['name'] ?? $v['id'],
                    'assignments'     => (int)$stats['assignments'],
                    'conversions'     => (int)$stats['conversions'],
                    'conversion_rate' => (int)$stats['assignments'] > 0
                        ? round(((int)$stats['conversions'] / (int)$stats['assignments']) * 100, 2)
                        : 0,
                    'revenue'         => round((float)$stats['revenue'], 2),
                    'avg_quality'     => round((float)$stats['avg_quality'], 1),
                    'avg_order_value' => round((float)$stats['avg_order_value'], 2),
                ];
            }

            // Recent assignments
            $recentStmt = $db->prepare("
                SELECT variant_id, customer_phone, converted, order_value, quality_score, assigned_at
                FROM om_ab_assignments
                WHERE test_id = ?
                ORDER BY assigned_at DESC
                LIMIT 20
            ");
            $recentStmt->execute([$id]);
            $recent = $recentStmt->fetchAll();

            foreach ($recent as &$r) {
                $r['converted'] = (bool)$r['converted'];
                $r['order_value'] = round((float)$r['order_value'], 2);
                $r['quality_score'] = (int)$r['quality_score'];
            }
            unset($r);

            response(true, [
                'test'           => $test,
                'variant_stats'  => array_values($variantStats),
                'recent_assignments' => $recent,
            ]);
        }

        // ── Statistical results ──
        if ($action === 'results') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                response(false, null, "Informe id do teste", 400);
            }

            $stmt = $db->prepare("SELECT * FROM om_ab_tests WHERE id = ?");
            $stmt->execute([$id]);
            $test = $stmt->fetch();

            if (!$test) {
                response(false, null, "Teste nao encontrado", 404);
            }

            $variants = json_decode($test['variants'], true) ?: [];
            $results = [];
            $bestRate = -1;
            $bestVariant = null;

            foreach ($variants as $v) {
                $vStmt = $db->prepare("
                    SELECT
                        COUNT(*) AS n,
                        COUNT(*) FILTER (WHERE converted = TRUE) AS conversions,
                        COALESCE(SUM(order_value) FILTER (WHERE converted = TRUE), 0) AS revenue,
                        COALESCE(AVG(quality_score) FILTER (WHERE quality_score > 0), 0) AS avg_quality
                    FROM om_ab_assignments
                    WHERE test_id = ? AND variant_id = ?
                ");
                $vStmt->execute([$id, $v['id']]);
                $s = $vStmt->fetch();

                $n = (int)$s['n'];
                $conv = (int)$s['conversions'];
                $rate = $n > 0 ? $conv / $n : 0;

                // Wilson score 95% confidence interval
                $z = 1.96;
                if ($n > 0) {
                    $phat = $rate;
                    $denom = 1 + ($z * $z / $n);
                    $center = ($phat + ($z * $z) / (2 * $n)) / $denom;
                    $spread = ($z / $denom) * sqrt(($phat * (1 - $phat) / $n) + ($z * $z / (4 * $n * $n)));
                    $ciLower = max(0, $center - $spread);
                    $ciUpper = min(1, $center + $spread);
                } else {
                    $ciLower = 0;
                    $ciUpper = 0;
                }

                $result = [
                    'variant_id'       => $v['id'],
                    'variant_name'     => $v['name'] ?? $v['id'],
                    'sample_size'      => $n,
                    'conversions'      => $conv,
                    'conversion_rate'  => round($rate * 100, 2),
                    'ci_lower'         => round($ciLower * 100, 2),
                    'ci_upper'         => round($ciUpper * 100, 2),
                    'revenue'          => round((float)$s['revenue'], 2),
                    'avg_quality'      => round((float)$s['avg_quality'], 1),
                    'sufficient_data'  => $n >= (int)$test['min_sample_size'],
                ];

                if ($rate > $bestRate && $n >= (int)$test['min_sample_size']) {
                    $bestRate = $rate;
                    $bestVariant = $v['id'];
                }

                $results[] = $result;
            }

            // Calculate overall confidence between top 2 variants (z-test for proportions)
            $confidence = null;
            if (count($results) >= 2) {
                usort($results, fn($a, $b) => $b['conversion_rate'] <=> $a['conversion_rate']);
                $a = $results[0];
                $b = $results[1];
                if ($a['sample_size'] > 0 && $b['sample_size'] > 0) {
                    $p1 = $a['conversions'] / $a['sample_size'];
                    $p2 = $b['conversions'] / $b['sample_size'];
                    $pPooled = ($a['conversions'] + $b['conversions']) / ($a['sample_size'] + $b['sample_size']);
                    if ($pPooled > 0 && $pPooled < 1) {
                        $se = sqrt($pPooled * (1 - $pPooled) * (1 / $a['sample_size'] + 1 / $b['sample_size']));
                        if ($se > 0) {
                            $zStat = abs($p1 - $p2) / $se;
                            // Approximate p-value from z-score (one-sided)
                            $confidence = round(min(99.99, (1 - exp(-0.717 * $zStat - 0.416 * $zStat * $zStat)) * 100), 2);
                        }
                    }
                }
            }

            response(true, [
                'test_id'    => (int)$test['id'],
                'test_name'  => $test['name'],
                'status'     => $test['status'],
                'variants'   => $results,
                'winner'     => $bestVariant,
                'confidence' => $confidence,
                'min_sample' => (int)$test['min_sample_size'],
            ]);
        }

        response(false, null, "Action GET invalida. Valores: list, detail, results", 400);
    }

    // ════════════════════════════════════════════════════════════════════
    // POST actions
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? ($_POST['action'] ?? '');

        if (!$action) {
            response(false, null, "Informe action: create, start, pause, complete, assign, record_result", 400);
        }

        // ── Create new A/B test ──
        if ($action === 'create') {
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $testType = trim($input['test_type'] ?? '');
            $channel = trim($input['channel'] ?? '');
            $variants = $input['variants'] ?? [];
            $minSample = (int)($input['min_sample_size'] ?? 100);

            if ($name === '') {
                response(false, null, "Nome do teste e obrigatorio", 400);
            }

            $validTypes = ['prompt_style', 'upsell_strategy', 'greeting', 'tone'];
            if (!in_array($testType, $validTypes, true)) {
                response(false, null, "test_type invalido. Valores: " . implode(', ', $validTypes), 400);
            }

            $validChannels = ['voice', 'whatsapp', 'both'];
            if (!in_array($channel, $validChannels, true)) {
                response(false, null, "channel invalido. Valores: " . implode(', ', $validChannels), 400);
            }

            if (!is_array($variants) || count($variants) < 2) {
                response(false, null, "Minimo 2 variantes necessarias", 400);
            }

            // Validate variant structure
            $variantIds = [];
            foreach ($variants as &$v) {
                if (empty($v['id']) || empty($v['name'])) {
                    response(false, null, "Cada variante precisa de id e name", 400);
                }
                if (in_array($v['id'], $variantIds, true)) {
                    response(false, null, "IDs de variante duplicados: " . $v['id'], 400);
                }
                $variantIds[] = $v['id'];
                $v['weight'] = (int)($v['weight'] ?? 1);
                if ($v['weight'] < 1) {
                    $v['weight'] = 1;
                }
                $v['config'] = $v['config'] ?? [];
            }
            unset($v);

            if ($minSample < 10) {
                $minSample = 10;
            }

            $stmt = $db->prepare("
                INSERT INTO om_ab_tests (name, description, test_type, channel, variants, min_sample_size, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $stmt->execute([
                $name,
                $description,
                $testType,
                $channel,
                json_encode($variants),
                $minSample,
                $adminId,
            ]);
            $newId = (int)$stmt->fetchColumn();

            response(true, ['id' => $newId, 'message' => 'Teste A/B criado com sucesso']);
        }

        // ── Start test ──
        if ($action === 'start') {
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                response(false, null, "Informe id do teste", 400);
            }

            $stmt = $db->prepare("SELECT status FROM om_ab_tests WHERE id = ?");
            $stmt->execute([$id]);
            $test = $stmt->fetch();

            if (!$test) {
                response(false, null, "Teste nao encontrado", 404);
            }

            if (!in_array($test['status'], ['draft', 'paused'], true)) {
                response(false, null, "Teste precisa estar em draft ou paused para iniciar. Status atual: " . $test['status'], 400);
            }

            $stmt = $db->prepare("
                UPDATE om_ab_tests SET status = 'running', started_at = COALESCE(started_at, NOW())
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            response(true, ['message' => 'Teste iniciado com sucesso']);
        }

        // ── Pause test ──
        if ($action === 'pause') {
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                response(false, null, "Informe id do teste", 400);
            }

            $stmt = $db->prepare("SELECT status FROM om_ab_tests WHERE id = ?");
            $stmt->execute([$id]);
            $test = $stmt->fetch();

            if (!$test) {
                response(false, null, "Teste nao encontrado", 404);
            }

            if ($test['status'] !== 'running') {
                response(false, null, "Apenas testes running podem ser pausados", 400);
            }

            $stmt = $db->prepare("UPDATE om_ab_tests SET status = 'paused' WHERE id = ?");
            $stmt->execute([$id]);

            response(true, ['message' => 'Teste pausado']);
        }

        // ── Complete test ──
        if ($action === 'complete') {
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                response(false, null, "Informe id do teste", 400);
            }

            $stmt = $db->prepare("SELECT * FROM om_ab_tests WHERE id = ?");
            $stmt->execute([$id]);
            $test = $stmt->fetch();

            if (!$test) {
                response(false, null, "Teste nao encontrado", 404);
            }

            if (!in_array($test['status'], ['running', 'paused'], true)) {
                response(false, null, "Teste precisa estar running ou paused para completar", 400);
            }

            $variants = json_decode($test['variants'], true) ?: [];
            $bestScore = -1;
            $winnerVariant = null;
            $metricsData = [];

            foreach ($variants as $v) {
                $vStmt = $db->prepare("
                    SELECT
                        COUNT(*) AS n,
                        COUNT(*) FILTER (WHERE converted = TRUE) AS conversions,
                        COALESCE(SUM(order_value) FILTER (WHERE converted = TRUE), 0) AS revenue,
                        COALESCE(AVG(quality_score) FILTER (WHERE quality_score > 0), 0) AS avg_quality
                    FROM om_ab_assignments
                    WHERE test_id = ? AND variant_id = ?
                ");
                $vStmt->execute([$id, $v['id']]);
                $s = $vStmt->fetch();

                $n = (int)$s['n'];
                $conv = (int)$s['conversions'];
                $rate = $n > 0 ? $conv / $n : 0;
                $quality = (float)$s['avg_quality'];

                // Combined score: 70% conversion + 30% quality
                $combined = ($rate * 70) + (($quality / 100) * 30);

                $metricsData[$v['id']] = [
                    'impressions'     => $n,
                    'conversions'     => $conv,
                    'conversion_rate' => round($rate * 100, 2),
                    'revenue'         => round((float)$s['revenue'], 2),
                    'avg_quality'     => round($quality, 1),
                    'combined_score'  => round($combined, 2),
                ];

                if ($combined > $bestScore && $n > 0) {
                    $bestScore = $combined;
                    $winnerVariant = $v['id'];
                }
            }

            // Confidence level between top 2
            $confidence = 0;
            $sorted = $metricsData;
            uasort($sorted, fn($a, $b) => $b['combined_score'] <=> $a['combined_score']);
            $sortedKeys = array_keys($sorted);
            if (count($sortedKeys) >= 2) {
                $top = $sorted[$sortedKeys[0]];
                $second = $sorted[$sortedKeys[1]];
                if ($top['impressions'] > 0 && $second['impressions'] > 0) {
                    $p1 = $top['conversions'] / $top['impressions'];
                    $p2 = $second['conversions'] / $second['impressions'];
                    $pPooled = ($top['conversions'] + $second['conversions']) / ($top['impressions'] + $second['impressions']);
                    if ($pPooled > 0 && $pPooled < 1) {
                        $se = sqrt($pPooled * (1 - $pPooled) * (1 / $top['impressions'] + 1 / $second['impressions']));
                        if ($se > 0) {
                            $zStat = abs($p1 - $p2) / $se;
                            $confidence = round(min(99.99, (1 - exp(-0.717 * $zStat - 0.416 * $zStat * $zStat)) * 100), 2);
                        }
                    }
                }
            }

            $stmt = $db->prepare("
                UPDATE om_ab_tests
                SET status = 'completed', ended_at = NOW(),
                    winner_variant = ?, confidence_level = ?, metrics = ?
                WHERE id = ?
            ");
            $stmt->execute([$winnerVariant, $confidence, json_encode($metricsData), $id]);

            response(true, [
                'message'          => 'Teste completado',
                'winner_variant'   => $winnerVariant,
                'confidence_level' => $confidence,
                'metrics'          => $metricsData,
            ]);
        }

        // ── Assign customer to variant ──
        if ($action === 'assign') {
            $testId = (int)($input['test_id'] ?? 0);
            $phone = trim($input['customer_phone'] ?? '');
            $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
            $variantId = trim($input['variant_id'] ?? '');
            $convType = trim($input['conversation_type'] ?? '');
            $convId = isset($input['conversation_id']) ? (int)$input['conversation_id'] : null;

            if ($testId <= 0) {
                response(false, null, "test_id obrigatorio", 400);
            }
            if ($phone === '' && !$customerId) {
                response(false, null, "customer_phone ou customer_id obrigatorio", 400);
            }
            if ($variantId === '') {
                response(false, null, "variant_id obrigatorio", 400);
            }

            // Verify test exists and is running
            $stmt = $db->prepare("SELECT status FROM om_ab_tests WHERE id = ?");
            $stmt->execute([$testId]);
            $test = $stmt->fetch();

            if (!$test) {
                response(false, null, "Teste nao encontrado", 404);
            }
            if ($test['status'] !== 'running') {
                response(false, null, "Teste nao esta ativo", 400);
            }

            $stmt = $db->prepare("
                INSERT INTO om_ab_assignments (test_id, customer_phone, customer_id, variant_id, conversation_type, conversation_id)
                VALUES (?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $stmt->execute([$testId, $phone ?: null, $customerId, $variantId, $convType ?: null, $convId]);
            $assignId = (int)$stmt->fetchColumn();

            response(true, ['assignment_id' => $assignId]);
        }

        // ── Record conversion result ──
        if ($action === 'record_result') {
            $testId = (int)($input['test_id'] ?? 0);
            $phone = trim($input['customer_phone'] ?? '');
            $converted = (bool)($input['converted'] ?? false);
            $orderValue = (float)($input['order_value'] ?? 0);
            $qualityScore = (int)($input['quality_score'] ?? 0);

            if ($testId <= 0) {
                response(false, null, "test_id obrigatorio", 400);
            }
            if ($phone === '') {
                response(false, null, "customer_phone obrigatorio", 400);
            }

            // Update most recent assignment for this phone+test
            $stmt = $db->prepare("
                UPDATE om_ab_assignments
                SET converted = ?, order_value = ?, quality_score = ?
                WHERE id = (
                    SELECT id FROM om_ab_assignments
                    WHERE test_id = ? AND customer_phone = ?
                    ORDER BY assigned_at DESC LIMIT 1
                )
            ");
            $stmt->execute([$converted, $orderValue, $qualityScore, $testId, $phone]);

            if ($stmt->rowCount() === 0) {
                response(false, null, "Nenhuma atribuicao encontrada para este telefone/teste", 404);
            }

            response(true, ['message' => 'Resultado registrado']);
        }

        response(false, null, "Action POST invalida. Valores: create, start, pause, complete, assign, record_result", 400);
    }

    // Method not allowed
    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/callcenter/ab-testing] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
