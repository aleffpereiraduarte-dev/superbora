<?php
/**
 * GET/POST /api/mercado/admin/fraud.php
 * Admin fraud dashboard — reads om_fraud_signals logged by fraud-check.php
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    // Check table exists
    $tableCheck = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'om_fraud_signals')");
    if (!$tableCheck->fetchColumn()) {
        response(true, [
            'stats' => ['total' => 0, 'blocked' => 0, 'review' => 0, 'avg_score' => 0],
            'signals' => [],
            'pagination' => ['page' => 1, 'limit' => 20, 'total' => 0, 'pages' => 0]
        ]);
    }

    if ($method === 'GET') {
        $view = $_GET['view'] ?? 'list';
        $signalId = (int)($_GET['id'] ?? 0);

        // Stats
        if ($view === 'stats') {
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN action = 'block' THEN 1 ELSE 0 END) as blocked,
                    SUM(CASE WHEN action = 'review' THEN 1 ELSE 0 END) as review,
                    SUM(CASE WHEN action = 'allow' THEN 1 ELSE 0 END) as allowed,
                    ROUND(AVG(score)::numeric, 1) as avg_score,
                    SUM(CASE WHEN reviewed_by IS NOT NULL THEN 1 ELSE 0 END) as reviewed,
                    SUM(CASE WHEN reviewed_by IS NULL AND action IN ('block','review') THEN 1 ELSE 0 END) as pending_review
                FROM om_fraud_signals
            ");
            $stats = $stmt->fetch();

            // Score distribution
            $stmtDist = $db->query("
                SELECT
                    CASE
                        WHEN score >= 70 THEN 'alto (70-100)'
                        WHEN score >= 40 THEN 'medio (40-69)'
                        ELSE 'baixo (1-39)'
                    END as faixa,
                    COUNT(*) as total
                FROM om_fraud_signals
                WHERE created_at > NOW() - INTERVAL '30 days'
                GROUP BY faixa
                ORDER BY MIN(score) DESC
            ");
            $stats['distribution'] = $stmtDist->fetchAll();

            // Top signal types
            $stmtTypes = $db->query("
                SELECT signal_type, COUNT(*) as total
                FROM (
                    SELECT jsonb_array_elements(signals->'signals')->>'type' as signal_type
                    FROM om_fraud_signals
                    WHERE created_at > NOW() - INTERVAL '30 days'
                ) sub
                GROUP BY signal_type
                ORDER BY total DESC
                LIMIT 8
            ");
            $stats['top_signals'] = $stmtTypes->fetchAll();

            // Chart (last 14 days)
            $stmtChart = $db->query("
                SELECT DATE(created_at) as dia, COUNT(*) as total,
                    SUM(CASE WHEN action = 'block' THEN 1 ELSE 0 END) as blocked,
                    ROUND(AVG(score)::numeric, 1) as avg_score
                FROM om_fraud_signals
                WHERE created_at > NOW() - INTERVAL '14 days'
                GROUP BY DATE(created_at)
                ORDER BY dia
            ");
            $stats['chart'] = $stmtChart->fetchAll();

            // Top flagged customers — FIX: c.id -> c.customer_id, c.nome -> c.name
            $stmtCustomers = $db->query("
                SELECT f.customer_id, c.name as customer_name, c.email,
                    COUNT(*) as flag_count, MAX(f.score) as max_score, MAX(f.created_at) as last_flag
                FROM om_fraud_signals f
                LEFT JOIN om_market_customers c ON c.customer_id = f.customer_id
                WHERE f.created_at > NOW() - INTERVAL '30 days'
                GROUP BY f.customer_id, c.name, c.email
                HAVING COUNT(*) >= 2
                ORDER BY max_score DESC, flag_count DESC
                LIMIT 10
            ");
            $stats['top_customers'] = $stmtCustomers->fetchAll();

            response(true, ['stats' => $stats]);
        }

        // Detail — FIX: c.id -> c.customer_id, c.nome -> c.name, c.celular -> c.phone
        if ($signalId) {
            $stmt = $db->prepare("
                SELECT f.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone
                FROM om_fraud_signals f
                LEFT JOIN om_market_customers c ON c.customer_id = f.customer_id
                WHERE f.id = ?
            ");
            $stmt->execute([$signalId]);
            $signal = $stmt->fetch();
            if (!$signal) response(false, null, "Sinal nao encontrado", 404);

            // Customer history
            $stmtHist = $db->prepare("
                SELECT id, score, action, signals, ip_address, reviewed_by, reviewed_at, created_at
                FROM om_fraud_signals
                WHERE customer_id = ?
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmtHist->execute([$signal['customer_id']]);
            $signal['customer_history'] = $stmtHist->fetchAll();

            response(true, ['signal' => $signal]);
        }

        // List
        $action_filter = $_GET['action'] ?? null;
        $reviewed = $_GET['reviewed'] ?? null;
        $period = $_GET['period'] ?? '30d';
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $where = "1=1";
        $params = [];

        // FIX: Whitelist action_filter to prevent unexpected values
        if ($action_filter) {
            if (!in_array($action_filter, ['allow', 'review', 'block'], true)) {
                response(false, null, "Filtro de acao invalido", 400);
            }
            $where .= " AND f.action = ?";
            $params[] = $action_filter;
        }
        if ($reviewed === 'yes') {
            $where .= " AND f.reviewed_by IS NOT NULL";
        } elseif ($reviewed === 'no') {
            $where .= " AND f.reviewed_by IS NULL";
        }

        // FIX: Use parameterized INTERVAL instead of string interpolation
        $periodMap = ['7d' => '7 days', '30d' => '30 days', '90d' => '90 days', 'all' => null];
        $intervalStr = $periodMap[$period] ?? '30 days';
        if ($intervalStr) {
            $where .= " AND f.created_at > NOW() - CAST(? AS INTERVAL)";
            $params[] = $intervalStr;
        }

        // FIX: Escape LIKE wildcards in search input, fix column names
        if ($search) {
            if (mb_strlen($search) > 100) {
                response(false, null, "Busca muito longa", 400);
            }
            $searchEscaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $where .= " AND (c.name ILIKE ? ESCAPE '\\' OR c.email ILIKE ? ESCAPE '\\' OR CAST(f.customer_id AS TEXT) LIKE ? ESCAPE '\\')";
            $params[] = "%$searchEscaped%";
            $params[] = "%$searchEscaped%";
            $params[] = "%$searchEscaped%";
        }

        // FIX: c.id -> c.customer_id
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_fraud_signals f LEFT JOIN om_market_customers c ON c.customer_id = f.customer_id WHERE $where");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // FIX: c.id -> c.customer_id, c.nome -> c.name, use parameterized LIMIT/OFFSET
        $stmt = $db->prepare("
            SELECT f.id, f.customer_id, f.score, f.action, f.signals, f.ip_address,
                f.reviewed_by, f.reviewed_at, f.created_at,
                c.name as customer_name, c.email as customer_email
            FROM om_fraud_signals f
            LEFT JOIN om_market_customers c ON c.customer_id = f.customer_id
            WHERE $where
            ORDER BY f.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $signals = $stmt->fetchAll();

        response(true, [
            'signals' => $signals,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => $total > 0 ? (int)ceil($total / $limit) : 0]
        ]);
    }

    // POST — mark as reviewed
    if ($method === 'POST') {
        $input = getInput();
        $signalId = (int)($input['id'] ?? 0);
        $action = $input['action'] ?? '';

        if (!$signalId) response(false, null, "id obrigatorio", 400);

        if ($action === 'review') {
            $db->prepare("UPDATE om_fraud_signals SET reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([$adminId, $signalId]);
            response(true, ['message' => 'Marcado como revisado']);
        }

        if ($action === 'block_customer') {
            $stmt = $db->prepare("SELECT customer_id FROM om_fraud_signals WHERE id = ?");
            $stmt->execute([$signalId]);
            $customerId = (int)$stmt->fetchColumn();
            if ($customerId) {
                try {
                    // FIX: WHERE id -> WHERE customer_id (correct PK column)
                    $db->prepare("UPDATE om_market_customers SET status = 'blocked' WHERE customer_id = ?")->execute([$customerId]);
                } catch (Exception $e) {
                    // status column may not exist
                    error_log("[admin/fraud] block_customer error: " . $e->getMessage());
                }
            }
            $db->prepare("UPDATE om_fraud_signals SET reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([$adminId, $signalId]);
            response(true, ['message' => "Cliente #$customerId bloqueado"]);
        }

        response(false, null, "Acao invalida", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/fraud] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
