<?php
/**
 * API: Ranking de Trabalhadores
 * GET /api/ranking.php - Obter ranking
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Obter ranking
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $period = $_GET['period'] ?? 'week'; // week, month, all
    $region = $_GET['region'] ?? null;

    try {
        // Definir período
        switch ($period) {
            case 'week':
                $dateFilter = "AND o.completed_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)";
                break;
            case 'month':
                $dateFilter = "AND o.completed_at >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
                break;
            default:
                $dateFilter = "";
        }

        // Top 50 trabalhadores
        $stmt = $db->prepare("
            SELECT 
                w.id, w.name, w.photo, w.rating,
                COUNT(o.id) as total_orders,
                SUM(o.earnings) as total_earnings,
                AVG(o.earnings) as avg_earnings
            FROM " . table('workers') . " w
            LEFT JOIN " . table('orders') . " o ON o.worker_id = w.id AND o.status = 'completed' $dateFilter
            WHERE w.status = 'active'
            GROUP BY w.id
            HAVING total_orders > 0
            ORDER BY total_orders DESC
            LIMIT 50
        ");
        $stmt->execute();
        $ranking = $stmt->fetchAll();

        // Adicionar posição
        foreach ($ranking as $i => &$worker) {
            $worker['position'] = $i + 1;
            $worker['is_me'] = $worker['id'] == $workerId;
        }

        // Minha posição se não estiver no top 50
        $myPosition = null;
        $myStats = null;
        $inTop50 = array_filter($ranking, fn($w) => $w['id'] == $workerId);

        if (empty($inTop50)) {
            // Buscar minha posição
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) + 1 as position
                FROM " . table('workers') . " w
                LEFT JOIN " . table('orders') . " o ON o.worker_id = w.id AND o.status = 'completed' $dateFilter
                WHERE w.status = 'active'
                GROUP BY w.id
                HAVING COUNT(o.id) > (
                    SELECT COUNT(*) FROM " . table('orders') . " 
                    WHERE worker_id = ? AND status = 'completed' 
                    " . str_replace('o.', '', $dateFilter) . "
                )
            ");
            $stmt->execute([$workerId]);
            $result = $stmt->fetch();
            $myPosition = $result ? $result['position'] : null;

            // Minhas estatísticas
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_orders,
                    COALESCE(SUM(earnings), 0) as total_earnings
                FROM " . table('orders') . "
                WHERE worker_id = ? AND status = 'completed' 
                " . str_replace('o.', '', $dateFilter)
            );
            $stmt->execute([$workerId]);
            $myStats = $stmt->fetch();
        }

        // Prêmios do ranking
        $prizes = [
            ['position' => 1, 'prize' => 'R$ 500 + Badge Ouro', 'icon' => '🥇'],
            ['position' => 2, 'prize' => 'R$ 300 + Badge Prata', 'icon' => '🥈'],
            ['position' => 3, 'prize' => 'R$ 200 + Badge Bronze', 'icon' => '🥉'],
            ['position' => '4-10', 'prize' => 'R$ 100', 'icon' => '🏆'],
            ['position' => '11-50', 'prize' => 'R$ 50', 'icon' => '⭐'],
        ];

        jsonSuccess([
            'ranking' => $ranking,
            'my_position' => $myPosition,
            'my_stats' => $myStats,
            'prizes' => $prizes,
            'period' => $period,
            'ends_at' => date('Y-m-d 23:59:59', strtotime('next sunday'))
        ]);

    } catch (Exception $e) {
        error_log("Ranking error: " . $e->getMessage());
        jsonError('Erro ao buscar ranking', 500);
    }
}

jsonError('Método não permitido', 405);
