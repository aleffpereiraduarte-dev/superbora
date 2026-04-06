<?php
/**
 * /api/mercado/admin/ab-testing.php
 *
 * A/B Testing management endpoint.
 * Integrates with the sb-ab-testing Go microservice on port 8658.
 *
 * GET:                List all A/B tests (with optional ?status= filter)
 * GET ?id=X:          Get test details with per-variant results
 * POST:               Create a new test {name, description, variants, metric}
 * POST ?action=pause&id=X:    Pause a test
 * POST ?action=complete&id=X: Complete (end) a test
 * POST ?action=assign&test_id=X&customer_id=Y: Get variant for customer
 * POST ?action=convert&test_id=X&customer_id=Y&value=Z: Track conversion
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/ab-helper.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    // ── Ensure tables exist ──
    ensureAbTables($db);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = trim($_GET['action'] ?? '');

    // =================== GET ===================
    if ($method === 'GET') {

        // Single test detail
        if (!empty($_GET['id'])) {
            $testId = (int)$_GET['id'];
            $stmt = $db->prepare("SELECT * FROM sb_ab_tests WHERE id = ?");
            $stmt->execute([$testId]);
            $test = $stmt->fetch();
            if (!$test) {
                response(false, null, "Teste nao encontrado", 404);
            }

            $test['variants'] = json_decode($test['variants'], true);

            // Get assignment stats per variant
            $stmt = $db->prepare("
                SELECT variant_name,
                       COUNT(*) as participants,
                       SUM(CASE WHEN converted THEN 1 ELSE 0 END) as conversions,
                       COALESCE(SUM(conversion_value), 0) as total_value,
                       CASE WHEN COUNT(*) > 0
                            THEN ROUND(SUM(CASE WHEN converted THEN 1 ELSE 0 END)::numeric / COUNT(*)::numeric * 100, 2)
                            ELSE 0
                       END as conversion_rate
                FROM sb_ab_assignments
                WHERE test_id = ?
                GROUP BY variant_name
                ORDER BY variant_name
            ");
            $stmt->execute([$testId]);
            $test['results'] = $stmt->fetchAll();

            // Statistical significance (chi-square approximation for 2 variants)
            if (count($test['results']) === 2) {
                $test['significance'] = calculateSignificance($test['results']);
            }

            response(true, $test, "Detalhes do teste");
        }

        // List all tests
        $status = trim($_GET['status'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ["1=1"];
        $params = [];

        if ($status && in_array($status, ['active', 'paused', 'completed'], true)) {
            $where[] = "status = ?";
            $params[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $db->prepare("SELECT COUNT(*) as total FROM sb_ab_tests WHERE {$whereSql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        $params[] = $limit;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT id, name, description, status, metric, total_participants,
                   variants, created_at, ended_at
            FROM sb_ab_tests
            WHERE {$whereSql}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $tests = $stmt->fetchAll();

        foreach ($tests as &$t) {
            $t['variants'] = json_decode($t['variants'], true);
        }
        unset($t);

        response(true, [
            'tests' => $tests,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
        ], "Testes A/B listados");
    }

    // =================== POST ===================
    if ($method === 'POST') {

        // ── Assign variant to customer ──
        if ($action === 'assign') {
            $testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
            $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
            if (!$testId || !$customerId) {
                response(false, null, "test_id e customer_id obrigatorios", 400);
            }

            $variant = getVariant($db, $testId, $customerId);
            if ($variant === null) {
                response(false, null, "Teste nao encontrado ou inativo", 404);
            }

            response(true, ['variant' => $variant, 'test_id' => $testId, 'customer_id' => $customerId], "Variante atribuida");
        }

        // ── Track conversion ──
        if ($action === 'convert') {
            $testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
            $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
            $value = (float)($_GET['value'] ?? $_POST['value'] ?? 1);
            if (!$testId || !$customerId) {
                response(false, null, "test_id e customer_id obrigatorios", 400);
            }

            $tracked = trackConversion($db, $testId, $customerId, $value);
            response($tracked, ['tracked' => $tracked], $tracked ? "Conversao registrada" : "Conversao ja registrada ou teste nao encontrado");
        }

        // ── Pause test ──
        if ($action === 'pause') {
            $testId = (int)($_GET['id'] ?? 0);
            if (!$testId) response(false, null, "id obrigatorio", 400);

            $stmt = $db->prepare("UPDATE sb_ab_tests SET status = 'paused' WHERE id = ? AND status = 'active'");
            $stmt->execute([$testId]);
            if ($stmt->rowCount() === 0) {
                response(false, null, "Teste nao encontrado ou nao esta ativo", 404);
            }

            om_audit()->log('ab_test_paused', 'sb_ab_tests', $testId, null, null, $admin_id);
            response(true, null, "Teste pausado");
        }

        // ── Complete test ──
        if ($action === 'complete') {
            $testId = (int)($_GET['id'] ?? 0);
            if (!$testId) response(false, null, "id obrigatorio", 400);

            $stmt = $db->prepare("UPDATE sb_ab_tests SET status = 'completed', ended_at = NOW() WHERE id = ? AND status IN ('active', 'paused')");
            $stmt->execute([$testId]);
            if ($stmt->rowCount() === 0) {
                response(false, null, "Teste nao encontrado ou ja completado", 404);
            }

            om_audit()->log('ab_test_completed', 'sb_ab_tests', $testId, null, null, $admin_id);
            response(true, null, "Teste finalizado");
        }

        // ── Create new test ──
        $input = getInput();
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $variants = $input['variants'] ?? [];
        $metric = trim($input['metric'] ?? 'conversion');

        if (!$name) {
            response(false, null, "Nome do teste obrigatorio", 400);
        }
        if (!is_array($variants) || count($variants) < 2) {
            response(false, null, "Pelo menos 2 variantes sao obrigatorias", 400);
        }

        $allowedMetrics = ['conversion', 'revenue', 'engagement'];
        if (!in_array($metric, $allowedMetrics, true)) {
            response(false, null, "Metrica invalida. Use: " . implode(', ', $allowedMetrics), 400);
        }

        // Validate and normalize variants
        $normalizedVariants = [];
        foreach ($variants as $v) {
            $vName = trim($v['name'] ?? '');
            if (!$vName) {
                response(false, null, "Cada variante precisa de um nome", 400);
            }
            if (strlen($vName) > 100) {
                response(false, null, "Nome da variante muito longo (max 100)", 400);
            }
            $normalizedVariants[] = [
                'name' => sanitizeOutput($vName),
                'weight' => max(1, (int)($v['weight'] ?? 1)),
                'conversions' => 0,
                'total_value' => 0,
            ];
        }

        // Check for duplicate variant names
        $vNames = array_column($normalizedVariants, 'name');
        if (count($vNames) !== count(array_unique($vNames))) {
            response(false, null, "Nomes de variantes devem ser unicos", 400);
        }

        $stmt = $db->prepare("
            INSERT INTO sb_ab_tests (name, description, status, variants, metric, created_at)
            VALUES (?, ?, 'active', ?::jsonb, ?, NOW())
            RETURNING id
        ");
        $stmt->execute([
            sanitizeOutput($name),
            sanitizeOutput($description),
            json_encode($normalizedVariants),
            $metric,
        ]);
        $testId = (int)$stmt->fetch()['id'];

        om_audit()->log('ab_test_created', 'sb_ab_tests', $testId, null, null, $admin_id);

        response(true, ['id' => $testId, 'name' => $name, 'variants' => $normalizedVariants], "Teste A/B criado", 201);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/ab-testing] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// ── Helper functions ──

function ensureAbTables(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $db->exec("
        CREATE TABLE IF NOT EXISTS sb_ab_tests (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            variants JSONB NOT NULL,
            metric VARCHAR(50) NOT NULL DEFAULT 'conversion',
            total_participants INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            ended_at TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS sb_ab_assignments (
            id SERIAL PRIMARY KEY,
            test_id INT NOT NULL,
            customer_id INT NOT NULL,
            variant_name VARCHAR(100) NOT NULL,
            converted BOOLEAN NOT NULL DEFAULT false,
            conversion_value NUMERIC(10,2),
            assigned_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE(test_id, customer_id)
        )
    ");

    // Index for fast lookups
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_ab_assignments_test_customer ON sb_ab_assignments(test_id, customer_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_ab_tests_status ON sb_ab_tests(status)");
}

/**
 * Chi-square significance test for 2 variants.
 * Returns p-value approximation and whether result is significant at 95%.
 */
function calculateSignificance(array $results): array {
    if (count($results) !== 2) {
        return ['significant' => false, 'confidence' => 0];
    }

    $a = $results[0];
    $b = $results[1];

    $n1 = (int)$a['participants'];
    $n2 = (int)$b['participants'];
    $c1 = (int)$a['conversions'];
    $c2 = (int)$b['conversions'];

    $total = $n1 + $n2;
    $totalConversions = $c1 + $c2;

    if ($total === 0 || $totalConversions === 0 || $totalConversions === $total) {
        return ['significant' => false, 'confidence' => 0, 'chi_square' => 0];
    }

    // Pooled conversion rate
    $p = $totalConversions / $total;
    $q = 1 - $p;

    // Expected values
    $e1c = $n1 * $p;
    $e1nc = $n1 * $q;
    $e2c = $n2 * $p;
    $e2nc = $n2 * $q;

    // Chi-square
    $chiSquare = 0;
    if ($e1c > 0) $chiSquare += pow($c1 - $e1c, 2) / $e1c;
    if ($e1nc > 0) $chiSquare += pow(($n1 - $c1) - $e1nc, 2) / $e1nc;
    if ($e2c > 0) $chiSquare += pow($c2 - $e2c, 2) / $e2c;
    if ($e2nc > 0) $chiSquare += pow(($n2 - $c2) - $e2nc, 2) / $e2nc;

    // 95% confidence = chi-square > 3.841 (1 degree of freedom)
    $significant = $chiSquare > 3.841;
    $confidence = $chiSquare > 6.635 ? 99 : ($chiSquare > 3.841 ? 95 : ($chiSquare > 2.706 ? 90 : 0));

    return [
        'significant' => $significant,
        'confidence' => $confidence,
        'chi_square' => round($chiSquare, 4),
        'variant_a' => $a['variant_name'],
        'variant_b' => $b['variant_name'],
        'rate_a' => (float)$a['conversion_rate'],
        'rate_b' => (float)$b['conversion_rate'],
    ];
}
