<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $rating    = isset($_GET['rating']) && $_GET['rating'] !== '' ? (int)$_GET['rating'] : null;
    $flagged   = isset($_GET['flagged']) && $_GET['flagged'] !== '' ? (int)$_GET['flagged'] : null;
    $partnerId = isset($_GET['partner_id']) && $_GET['partner_id'] !== '' ? (int)$_GET['partner_id'] : null;
    $status    = isset($_GET['status']) && $_GET['status'] !== '' ? trim($_GET['status']) : null;
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $limit     = 20;
    $offset    = ($page - 1) * $limit;

    // Read real reviews from om_loja_avaliacoes (not orders proxy).
    $where  = ["1=1"];
    $params = [];
    if ($rating !== null && $rating >= 1 && $rating <= 5) {
        $where[] = "a.nota = ?";
        $params[] = $rating;
    }
    if ($partnerId !== null) {
        $where[] = "a.loja_id = ?";
        $params[] = $partnerId;
    }
    if ($status !== null) {
        $where[] = "a.status = ?";
        $params[] = $status;
    }
    $where_sql = implode(' AND ', $where);

    // Total + star distribution + averages (all in one query for efficiency)
    $stmt = $db->prepare("
        SELECT
            COUNT(*)                                         AS total,
            COALESCE(AVG(a.nota), 0)                         AS nota_media,
            SUM(CASE WHEN a.nota = 5 THEN 1 ELSE 0 END)      AS qtd_5,
            SUM(CASE WHEN a.nota = 4 THEN 1 ELSE 0 END)      AS qtd_4,
            SUM(CASE WHEN a.nota = 3 THEN 1 ELSE 0 END)      AS qtd_3,
            SUM(CASE WHEN a.nota = 2 THEN 1 ELSE 0 END)      AS qtd_2,
            SUM(CASE WHEN a.nota = 1 THEN 1 ELSE 0 END)      AS qtd_1,
            SUM(CASE WHEN a.created_at >= NOW() - INTERVAL '7 days' THEN 1 ELSE 0 END) AS ultimos_7_dias,
            SUM(CASE WHEN DATE(a.created_at) = CURRENT_DATE THEN 1 ELSE 0 END)         AS hoje,
            SUM(CASE WHEN a.resposta IS NOT NULL AND a.resposta <> '' THEN 1 ELSE 0 END) AS com_resposta
        FROM om_loja_avaliacoes a
        WHERE {$where_sql}
    ");
    $stmt->execute($params);
    $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($agg['total'] ?? 0);
    $taxa_resposta = $total > 0
        ? round(((int)($agg['com_resposta'] ?? 0) / $total) * 100, 1)
        : 0;

    $stmt = $db->prepare("
        SELECT a.id,
               a.loja_id,
               a.customer_id,
               a.order_id,
               a.nota,
               a.titulo,
               a.comentario,
               a.resposta,
               a.resposta_data,
               a.status,
               a.created_at,
               p.name     AS partner_name,
               c.name     AS customer_name,
               o.total    AS order_total
        FROM om_loja_avaliacoes a
        LEFT JOIN om_market_partners p ON p.partner_id = a.loja_id
        LEFT JOIN om_customers       c ON c.customer_id = a.customer_id
        LEFT JOIN om_market_orders   o ON o.order_id = a.order_id
        WHERE {$where_sql}
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $avaliacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Frontend expects English field names (rating, comment, created_at as ISO).
    // Add aliases without removing the originals (mobile/partner clients still
    // read nota/comentario/resposta).
    foreach ($avaliacoes as &$row) {
        $row['rating']          = (int)$row['nota'];
        $row['comment']         = $row['comentario'];
        $row['admin_response']  = $row['resposta'];
        $row['store_response']  = $row['resposta'];
        $row['response_at']     = $row['resposta_data'];
    }
    unset($row);

    response(true, [
        'avaliacoes' => $avaliacoes,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => $total > 0 ? (int)ceil($total / $limit) : 0,
        ],
        'stats' => [
            'total'          => $total,
            'nota_media'     => round((float)($agg['nota_media'] ?? 0), 2),
            'hoje'           => (int)($agg['hoje'] ?? 0),
            'ultimos_7_dias' => (int)($agg['ultimos_7_dias'] ?? 0),
            'com_resposta'   => (int)($agg['com_resposta'] ?? 0),
            'taxa_resposta'  => $taxa_resposta,
            'distribuicao'   => [
                '5' => (int)($agg['qtd_5'] ?? 0),
                '4' => (int)($agg['qtd_4'] ?? 0),
                '3' => (int)($agg['qtd_3'] ?? 0),
                '2' => (int)($agg['qtd_2'] ?? 0),
                '1' => (int)($agg['qtd_1'] ?? 0),
            ],
        ],
    ], "Avaliacoes listadas");
} catch (Exception $e) {
    error_log("[admin/avaliacoes] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
