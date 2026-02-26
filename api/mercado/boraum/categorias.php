<?php
/**
 * GET /api/mercado/boraum/categorias.php
 *
 * Lista categorias/tipos de culinaria para a tela inicial (igual Uber Eats/iFood).
 * Retorna as tags com icone, imagem e contagem de lojas.
 *
 * Parametros:
 *   lat, lng  - Coordenadas do usuario (opcional, filtra por raio)
 *   raio      - Raio maximo em km (default 10, requer lat/lng)
 *   tipo      - Tipo de estabelecimento: restaurante, mercado, farmacia, etc (opcional)
 */
require_once __DIR__ . '/../config/database.php';
setCorsHeaders();
$db = getDB();
// Endpoint publico - nao requer autenticacao

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido. Use GET.", 405);
}

try {
    $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
    $raio = max(1, min(100, (float)($_GET['raio'] ?? 10)));
    $tipo = trim($_GET['tipo'] ?? '');
    $hasCoords = ($lat !== null && $lng !== null);

    // Query: tags com contagem de lojas ativas (e proximas se tiver coords)
    $params = [];

    $distanceSql = '';
    $havingSql = '';

    $partnerWhere = "p.status = '1'";
    if ($tipo !== '') {
        $partnerWhere .= " AND p.categoria = ?";
        $params[] = $tipo;
    }

    if ($hasCoords) {
        $distanceSql = "
            (6371 * ACOS(
                LEAST(1, GREATEST(-1,
                    COS(RADIANS(?)) * COS(RADIANS(COALESCE(p.lat, 0))) * COS(RADIANS(COALESCE(p.lng, 0)) - RADIANS(?))
                    + SIN(RADIANS(?)) * SIN(RADIANS(COALESCE(p.lat, 0)))
                ))
            ))
        ";
        $params[] = $lat;
        $params[] = $lng;
        $params[] = $lat;
    }

    if ($hasCoords) {
        // Subquery pra contar lojas dentro do raio por tag
        $sql = "
            SELECT t.id, t.name, t.slug, t.icon, t.image, t.sort_order,
                (
                    SELECT COUNT(DISTINCT l2.partner_id)
                    FROM om_partner_tag_links l2
                    INNER JOIN om_market_partners p ON p.partner_id = l2.partner_id AND {$partnerWhere}
                    WHERE l2.tag_id = t.id
                    AND {$distanceSql} <= ?
                ) AS total_lojas
            FROM om_partner_tags t
            WHERE t.active = 1
            ORDER BY t.sort_order
        ";
        $params[] = $raio;
    } else {
        // Sem coords: contar todas as lojas ativas por tag
        $sql = "
            SELECT t.id, t.name, t.slug, t.icon, t.image, t.sort_order,
                (
                    SELECT COUNT(DISTINCT l2.partner_id)
                    FROM om_partner_tag_links l2
                    INNER JOIN om_market_partners p ON p.partner_id = l2.partner_id AND {$partnerWhere}
                    WHERE l2.tag_id = t.id
                ) AS total_lojas
            FROM om_partner_tags t
            WHERE t.active = 1
            ORDER BY t.sort_order
        ";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $categorias = [];
    foreach ($rows as $r) {
        $categorias[] = [
            'id'          => (int)$r['id'],
            'nome'        => $r['name'],
            'slug'        => $r['slug'],
            'icone'       => $r['icon'] ?: null,
            'imagem'      => $r['image'] ?: null,
            'total_lojas' => (int)$r['total_lojas'],
        ];
    }

    // Separar: com lojas primeiro, sem lojas depois
    usort($categorias, function($a, $b) {
        if ($a['total_lojas'] > 0 && $b['total_lojas'] == 0) return -1;
        if ($a['total_lojas'] == 0 && $b['total_lojas'] > 0) return 1;
        return 0; // manter sort_order original
    });

    response(true, [
        'categorias' => $categorias,
    ]);

} catch (Exception $e) {
    error_log("[BoraUm categorias.php] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar categorias. Tente novamente.", 500);
}
