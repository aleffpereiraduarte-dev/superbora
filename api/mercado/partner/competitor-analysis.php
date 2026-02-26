<?php
/**
 * GET /api/mercado/partner/competitor-analysis.php - Analise de concorrentes
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Get partner's category
        $stmt = $db->prepare("SELECT categoria, cidade FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);
        $category = $partner['categoria'] ?? '';
        $city = $partner['cidade'] ?? '';

        // Get competitors in same category and city
        $stmt = $db->prepare("
            SELECT
                mp.partner_id,
                mp.name,
                mp.trade_name,
                mp.logo,
                (SELECT AVG(rating) FROM om_market_reviews WHERE partner_id = mp.partner_id) as avg_rating,
                (SELECT COUNT(*) FROM om_market_reviews WHERE partner_id = mp.partner_id) as review_count,
                (SELECT COUNT(*) FROM om_market_products WHERE partner_id = mp.partner_id AND status = '1') as product_count,
                (SELECT AVG(price) FROM om_market_products WHERE partner_id = mp.partner_id AND status = '1') as avg_price
            FROM om_market_partners mp
            WHERE mp.categoria = ? AND mp.cidade = ? AND mp.partner_id != ? AND mp.status = '1'
            ORDER BY avg_rating DESC
            LIMIT 10
        ");
        $stmt->execute([$category, $city, $partnerId]);
        $competitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get own stats for comparison
        $stmt = $db->prepare("
            SELECT
                (SELECT AVG(rating) FROM om_market_reviews WHERE partner_id = ?) as avg_rating,
                (SELECT COUNT(*) FROM om_market_reviews WHERE partner_id = ?) as review_count,
                (SELECT COUNT(*) FROM om_market_products WHERE partner_id = ? AND status = '1') as product_count,
                (SELECT AVG(price) FROM om_market_products WHERE partner_id = ? AND status = '1') as avg_price
        ");
        $stmt->execute([$partnerId, $partnerId, $partnerId, $partnerId]);
        $ownStats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Calculate market averages
        $avgRating = 0;
        $avgProducts = 0;
        $avgPrice = 0;
        if (count($competitors) > 0) {
            $avgRating = array_sum(array_column($competitors, 'avg_rating')) / count($competitors);
            $avgProducts = array_sum(array_column($competitors, 'product_count')) / count($competitors);
            $avgPrice = array_sum(array_filter(array_column($competitors, 'avg_price'))) / max(1, count(array_filter(array_column($competitors, 'avg_price'))));
        }

        // Generate insights
        $insights = [];

        // Rating comparison
        if ($ownStats['avg_rating'] > $avgRating + 0.3) {
            $insights[] = [
                'type' => 'success',
                'icon' => '⭐',
                'message' => "Sua avaliacao (" . round($ownStats['avg_rating'], 1) . ") esta acima da media do mercado (" . round($avgRating, 1) . ")!",
            ];
        } elseif ($ownStats['avg_rating'] < $avgRating - 0.3) {
            $insights[] = [
                'type' => 'warning',
                'icon' => '⚠️',
                'message' => "Sua avaliacao (" . round($ownStats['avg_rating'], 1) . ") esta abaixo da media (" . round($avgRating, 1) . "). Foque em qualidade!",
            ];
        }

        // Product count comparison
        if ($ownStats['product_count'] < $avgProducts * 0.7) {
            $insights[] = [
                'type' => 'info',
                'icon' => '📦',
                'message' => "Voce tem " . $ownStats['product_count'] . " produtos, a media e " . round($avgProducts) . ". Considere expandir o cardapio.",
            ];
        }

        // Price comparison
        if ($avgPrice > 0 && $ownStats['avg_price'] > $avgPrice * 1.2) {
            $insights[] = [
                'type' => 'info',
                'icon' => 'money',
                'message' => "Seus precos estao " . round(($ownStats['avg_price'] / $avgPrice - 1) * 100) . "% acima da media. Certifique-se de destacar o diferencial!",
            ];
        }

        // Ranking position
        $position = 1;
        foreach ($competitors as $comp) {
            if (($comp['avg_rating'] ?? 0) > ($ownStats['avg_rating'] ?? 0)) {
                $position++;
            }
        }

        response(true, [
            'your_stats' => [
                'avg_rating' => round((float)$ownStats['avg_rating'], 1),
                'review_count' => (int)$ownStats['review_count'],
                'product_count' => (int)$ownStats['product_count'],
                'avg_price' => round((float)$ownStats['avg_price'], 2),
            ],
            'market_averages' => [
                'avg_rating' => round($avgRating, 1),
                'avg_products' => round($avgProducts),
                'avg_price' => round($avgPrice, 2),
            ],
            'competitors' => array_map(function($c) {
                return [
                    'name' => $c['trade_name'] ?? $c['name'],
                    'logo' => $c['logo'],
                    'rating' => round((float)$c['avg_rating'], 1),
                    'reviews' => (int)$c['review_count'],
                    'products' => (int)$c['product_count'],
                ];
            }, $competitors),
            'your_ranking' => $position,
            'total_competitors' => count($competitors) + 1,
            'category' => $category,
            'insights' => $insights,
        ]);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/competitor-analysis] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
