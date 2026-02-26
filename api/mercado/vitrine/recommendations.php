<?php
/**
 * GET /api/mercado/vitrine/recommendations.php
 * Recomendacoes inteligentes baseadas em historico e preferencias
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $customerId = null;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && $payload['type'] === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }

    $type = $_GET['type'] ?? 'all'; // all, products, partners, reorder
    $limit = min(20, max(5, (int)($_GET['limit'] ?? 10)));
    $lat = (float)($_GET['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? 0);

    $recommendations = [];

    // Recomendacoes personalizadas para usuarios logados
    if ($customerId) {
        // Pedir de novo (baseado em historico)
        if ($type === 'all' || $type === 'reorder') {
            $stmt = $db->prepare("
                SELECT DISTINCT
                    p.partner_id, p.trade_name, p.logo, p.banner,
                    p.rating_average, p.delivery_time_min,
                    COUNT(DISTINCT o.order_id) as order_count,
                    MAX(o.created_at) as last_order
                FROM om_market_orders o
                INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE o.customer_id = ?
                AND o.status = 'entregue'
                AND p.status = '1'
                GROUP BY p.partner_id
                ORDER BY order_count DESC, last_order DESC
                LIMIT ?
            ");
            $stmt->execute([$customerId, $limit]);
            $reorderPartners = $stmt->fetchAll();

            if (!empty($reorderPartners)) {
                $recommendations['reorder'] = [
                    'title' => 'Pedir de novo',
                    'subtitle' => 'Seus restaurantes favoritos',
                    'items' => array_map(function($p) {
                        return [
                            'type' => 'partner',
                            'id' => (int)$p['partner_id'],
                            'name' => $p['trade_name'],
                            'image' => $p['logo'],
                            'rating' => (float)$p['rating_average'],
                            'delivery_time' => $p['delivery_time_min'],
                            'order_count' => (int)$p['order_count'],
                        ];
                    }, $reorderPartners)
                ];
            }
        }

        // Baseado em favoritos
        if ($type === 'all' || $type === 'favorites') {
            $stmt = $db->prepare("
                SELECT DISTINCT
                    p.partner_id, p.trade_name, p.logo, p.categoria,
                    p.rating_average, p.delivery_time_min
                FROM om_favorites f
                INNER JOIN om_market_partners p ON f.partner_id = p.partner_id
                WHERE f.customer_id = ? AND f.partner_id IS NOT NULL AND p.status = '1'
                ORDER BY f.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$customerId, $limit]);
            $favoritePartners = $stmt->fetchAll();

            // Buscar parceiros similares (mesma categoria)
            if (!empty($favoritePartners)) {
                $categories = array_unique(array_column($favoritePartners, 'categoria'));
                $placeholders = implode(',', array_fill(0, count($categories), '?'));
                $favoriteIds = array_column($favoritePartners, 'partner_id');
                $excludePlaceholders = implode(',', array_fill(0, count($favoriteIds), '?'));

                $stmt = $db->prepare("
                    SELECT p.partner_id, p.trade_name, p.logo, p.categoria,
                           p.rating_average, p.delivery_time_min
                    FROM om_market_partners p
                    WHERE p.categoria IN ($placeholders)
                    AND p.partner_id NOT IN ($excludePlaceholders)
                    AND p.status = '1'
                    ORDER BY p.rating_average DESC
                    LIMIT ?
                ");
                $stmt->execute(array_merge($categories, $favoriteIds, [$limit]));
                $similarPartners = $stmt->fetchAll();

                if (!empty($similarPartners)) {
                    $recommendations['similar'] = [
                        'title' => 'Voce pode gostar',
                        'subtitle' => 'Baseado nos seus favoritos',
                        'items' => array_map(function($p) {
                            return [
                                'type' => 'partner',
                                'id' => (int)$p['partner_id'],
                                'name' => $p['trade_name'],
                                'image' => $p['logo'],
                                'category' => $p['categoria'],
                                'rating' => (float)$p['rating_average'],
                                'delivery_time' => $p['delivery_time_min'],
                            ];
                        }, $similarPartners)
                    ];
                }
            }
        }
    }

    // Recomendacoes gerais (para todos)

    // Populares na regiao
    if ($type === 'all' || $type === 'popular') {
        $stmt = $db->prepare("
            SELECT p.partner_id, p.trade_name, p.logo, p.categoria,
                   p.rating_average, p.rating_count, p.delivery_time_min
            FROM om_market_partners p
            WHERE p.status = '1'
            ORDER BY p.rating_count DESC, p.rating_average DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $popular = $stmt->fetchAll();

        $recommendations['popular'] = [
            'title' => 'Mais populares',
            'subtitle' => 'Os mais pedidos da regiao',
            'items' => array_map(function($p) {
                return [
                    'type' => 'partner',
                    'id' => (int)$p['partner_id'],
                    'name' => $p['trade_name'],
                    'image' => $p['logo'],
                    'category' => $p['categoria'],
                    'rating' => (float)$p['rating_average'],
                    'review_count' => (int)$p['rating_count'],
                    'delivery_time' => $p['delivery_time_min'],
                ];
            }, $popular)
        ];
    }

    // Bem avaliados
    if ($type === 'all' || $type === 'top_rated') {
        $stmt = $db->prepare("
            SELECT p.partner_id, p.trade_name, p.logo, p.categoria,
                   p.rating_average, p.rating_count, p.delivery_time_min
            FROM om_market_partners p
            WHERE p.status = '1' AND p.rating_count >= 10
            ORDER BY p.rating_average DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $topRated = $stmt->fetchAll();

        $recommendations['top_rated'] = [
            'title' => 'Mais bem avaliados',
            'subtitle' => 'Nota 4.5 ou superior',
            'items' => array_map(function($p) {
                return [
                    'type' => 'partner',
                    'id' => (int)$p['partner_id'],
                    'name' => $p['trade_name'],
                    'image' => $p['logo'],
                    'category' => $p['categoria'],
                    'rating' => (float)$p['rating_average'],
                    'review_count' => (int)$p['rating_count'],
                ];
            }, $topRated)
        ];
    }

    // Novos na plataforma
    if ($type === 'all' || $type === 'new') {
        $stmt = $db->prepare("
            SELECT p.partner_id, p.trade_name, p.logo, p.categoria,
                   p.rating_average, p.delivery_time_min, p.created_at
            FROM om_market_partners p
            WHERE p.status = '1'
            AND p.created_at >= NOW() - INTERVAL '30 days'
            ORDER BY p.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $newPartners = $stmt->fetchAll();

        if (!empty($newPartners)) {
            $recommendations['new'] = [
                'title' => 'Novos por aqui',
                'subtitle' => 'Chegaram recentemente',
                'items' => array_map(function($p) {
                    return [
                        'type' => 'partner',
                        'id' => (int)$p['partner_id'],
                        'name' => $p['trade_name'],
                        'image' => $p['logo'],
                        'category' => $p['categoria'],
                        'rating' => (float)$p['rating_average'],
                        'is_new' => true,
                    ];
                }, $newPartners)
            ];
        }
    }

    // Produtos em promocao
    if ($type === 'all' || $type === 'deals') {
        $stmt = $db->prepare("
            SELECT pr.*, p.trade_name as partner_name, p.logo as partner_logo
            FROM om_market_products pr
            INNER JOIN om_market_partners p ON pr.partner_id = p.partner_id
            WHERE pr.status = '1' AND p.status = '1'
            AND pr.preco_promocional IS NOT NULL
            AND pr.preco_promocional < pr.preco
            ORDER BY (pr.preco - pr.preco_promocional) / pr.preco DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $deals = $stmt->fetchAll();

        if (!empty($deals)) {
            $recommendations['deals'] = [
                'title' => 'Ofertas do dia',
                'subtitle' => 'Aproveite os descontos',
                'items' => array_map(function($pr) {
                    $discount = round((($pr['preco'] - $pr['preco_promocional']) / $pr['preco']) * 100);
                    return [
                        'type' => 'product',
                        'id' => (int)$pr['id'],
                        'name' => $pr['nome'],
                        'image' => $pr['imagem'],
                        'original_price' => (float)$pr['preco'],
                        'sale_price' => (float)$pr['preco_promocional'],
                        'discount_percent' => $discount,
                        'partner' => [
                            'id' => (int)$pr['partner_id'],
                            'name' => $pr['partner_name'],
                            'logo' => $pr['partner_logo'],
                        ],
                    ];
                }, $deals)
            ];
        }
    }

    response(true, [
        'recommendations' => $recommendations,
        'is_personalized' => $customerId !== null
    ]);

} catch (Exception $e) {
    error_log("[vitrine/recommendations] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar recomendacoes", 500);
}
