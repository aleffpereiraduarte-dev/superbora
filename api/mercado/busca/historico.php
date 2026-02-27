<?php
/**
 * /api/mercado/busca/historico.php
 * Search History & Trending
 *
 * GET              - Get customer search history + trending
 * POST { clear }   - Clear customer search history
 * POST { query }   - Save search (called by search.php internally)
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    $method = $_SERVER["REQUEST_METHOD"];

    // Auth - optional for trending, required for history
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();
    $customerId = 0;
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && $payload['type'] === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }

    // ── GET: history + trending ──
    if ($method === "GET") {
        $city = trim($_GET['city'] ?? '');
        $result = [];

        // Customer search history (requires auth)
        if ($customerId) {
            $stmt = $db->prepare("
                SELECT DISTINCT ON (LOWER(query)) query, MAX(results_count) as results_count, MAX(created_at) as created_at
                FROM om_search_history
                WHERE customer_id = ?
                GROUP BY LOWER(query), query
                ORDER BY LOWER(query), MAX(created_at) DESC
                LIMIT 20
            ");
            $stmt->execute([$customerId]);
            $history = $stmt->fetchAll();

            // Re-sort by created_at DESC after DISTINCT ON
            usort($history, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

            $result['history'] = array_map(fn($h) => [
                'query' => $h['query'],
                'results_count' => (int)$h['results_count'],
            ], $history);
        } else {
            $result['history'] = [];
        }

        // Trending searches (public)
        $trendingWhere = "period >= CURRENT_DATE - INTERVAL '7 days'";
        $trendingParams = [];

        if ($city) {
            $trendingWhere .= " AND (city = ? OR city IS NULL)";
            $trendingParams[] = $city;
        }

        $stmt = $db->prepare("
            SELECT query, SUM(search_count) as total
            FROM om_search_trending
            WHERE {$trendingWhere}
            GROUP BY query
            ORDER BY total DESC
            LIMIT 10
        ");
        $stmt->execute($trendingParams);
        $result['trending'] = array_map(fn($t) => $t['query'], $stmt->fetchAll());

        response(true, $result);
    }

    // ── POST: save or clear ──
    if ($method === "POST") {
        $input = getInput();

        // Clear history
        if (!empty($input['clear'])) {
            if (!$customerId) response(false, null, "Autenticacao necessaria", 401);
            $db->prepare("DELETE FROM om_search_history WHERE customer_id = ?")->execute([$customerId]);
            response(true, null, "Historico limpo");
        }

        // Save search (called internally from search.php)
        $query = trim($input['query'] ?? '');
        $resultsCount = (int)($input['results_count'] ?? 0);
        $city = trim($input['city'] ?? '');

        if (!$query || strlen($query) < 2) {
            response(false, null, "Query muito curta", 400);
        }

        // Save to history (if authenticated)
        if ($customerId) {
            $db->prepare("
                INSERT INTO om_search_history (customer_id, query, results_count, city, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([$customerId, substr($query, 0, 255), $resultsCount, $city ?: null]);

            // Prune old entries (keep last 100 per customer)
            $db->prepare("
                DELETE FROM om_search_history
                WHERE customer_id = ? AND id NOT IN (
                    SELECT id FROM om_search_history WHERE customer_id = ?
                    ORDER BY created_at DESC LIMIT 100
                )
            ")->execute([$customerId, $customerId]);
        }

        // Update trending (aggregate)
        $db->prepare("
            INSERT INTO om_search_trending (query, city, search_count, period)
            VALUES (?, ?, 1, CURRENT_DATE)
            ON CONFLICT (query, city, period) DO UPDATE SET search_count = om_search_trending.search_count + 1
        ")->execute([strtolower(substr($query, 0, 255)), $city ?: null]);

        response(true, null, "Busca salva");
    }

} catch (Exception $e) {
    error_log("[busca/historico] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
