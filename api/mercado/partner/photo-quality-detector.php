<?php
/**
 * GET /api/mercado/partner/photo-quality-detector.php
 * Auth: partner
 * Lists products with weak/missing photos so the partner can fix them.
 *
 * Heuristic + AI: starts with file-based heuristic (size, missing, dimensions),
 * then optionally asks vision model to score top candidates.
 */
require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, 'Token ausente', 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, 'Nao autorizado', 401);
    }
    $partnerId = (int)$payload['uid'];

    $stmt = $db->prepare(
        "SELECT product_id, name, COALESCE(image,'') AS image
         FROM om_market_products
         WHERE partner_id = :pid AND status::text = '1'"
    );
    $stmt->execute([':pid' => $partnerId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $weak = [];
    foreach ($products as $p) {
        $issue = null;
        $score = 100;
        $img = $p['image'];

        if ($img === '' || $img === null) {
            $issue = 'sem foto';
            $score = 0;
        } else {
            // Try to find local file
            $localPath = null;
            if (strpos($img, '/uploads/') !== false) {
                $rel = ltrim(substr($img, strpos($img, '/uploads/')), '/');
                $candidates = ['/var/www/html/' . $rel];
                foreach ($candidates as $c) {
                    if (file_exists($c)) { $localPath = $c; break; }
                }
            }
            if ($localPath) {
                $size = filesize($localPath);
                if ($size < 5000) { $issue = 'arquivo muito pequeno (' . $size . 'B)'; $score = 30; }
                else {
                    $info = @getimagesize($localPath);
                    if ($info && ($info[0] < 200 || $info[1] < 200)) {
                        $issue = "resolucao baixa ({$info[0]}x{$info[1]})";
                        $score = 40;
                    } elseif ($info && ($info[0] < 500 || $info[1] < 500)) {
                        $issue = "resolucao mediana ({$info[0]}x{$info[1]})";
                        $score = 60;
                    }
                }
            } else if (strpos($img, '.svg') !== false) {
                $issue = 'placeholder SVG';
                $score = 10;
            }
        }

        if ($issue) {
            $weak[] = [
                'product_id' => (int)$p['product_id'],
                'name' => $p['name'],
                'image' => $img,
                'issue' => $issue,
                'score' => $score,
            ];
        }
    }

    usort($weak, fn($a, $b) => $a['score'] - $b['score']);

    response(true, [
        'partner_id' => $partnerId,
        'total_products' => count($products),
        'weak_count' => count($weak),
        'weak_pct' => count($products) > 0 ? round((count($weak) / count($products)) * 100, 1) : 0,
        'top_issues' => array_slice($weak, 0, 50),
    ]);
} catch (Exception $e) {
    error_log('[photo-quality] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
