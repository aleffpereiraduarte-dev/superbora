<?php
/**
 * GET /api/mercado/avaliacoes/listar.php?partner_id=X&limit=10&page=1
 * A5: Listar avaliacoes publicas de um parceiro
 * Cache 120s
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=120');

try {
    $partner_id = (int)($_GET["partner_id"] ?? 0);
    $limit = min(20, max(1, (int)($_GET["limit"] ?? 10)));
    $page = max(1, (int)($_GET["page"] ?? 1));
    $offset = ($page - 1) * $limit;

    if (!$partner_id) {
        response(false, null, "partner_id obrigatorio", 400);
    }

    $cacheKey = "avaliacoes_lista_" . md5(json_encode([$partner_id, $page, $limit]));

    $data = CacheHelper::remember($cacheKey, 120, function() use ($partner_id, $limit, $offset, $page) {
        $db = getDB();

        // Total de avaliacoes
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM om_market_ratings
            WHERE rated_type = 'partner' AND rated_id = ? AND is_public = 1
        ");
        $stmt->execute([$partner_id]);
        $total = (int)$stmt->fetchColumn();

        // Media e distribuicao
        $stmt = $db->prepare("
            SELECT
                AVG(rating) as media,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as r5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as r4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as r3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as r2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as r1
            FROM om_market_ratings
            WHERE rated_type = 'partner' AND rated_id = ? AND is_public = 1
        ");
        $stmt->execute([$partner_id]);
        $stats = $stmt->fetch();

        // Listar avaliacoes
        $stmt = $db->prepare("
            SELECT r.rating, r.comment, r.created_at,
                   COALESCE(c.firstname, 'Cliente') as customer_name
            FROM om_market_ratings r
            LEFT JOIN oc_customer c ON r.rater_id = c.customer_id AND r.rater_type = 'customer'
            WHERE r.rated_type = 'partner' AND r.rated_id = ? AND r.is_public = 1
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$partner_id, $limit, $offset]);

        $avaliacoes = [];
        foreach ($stmt->fetchAll() as $row) {
            // Anonimizar nome: primeiro char + *** (mais seguro para nomes curtos)
            $nome = $row['customer_name'] ?? 'Cliente';
            $nomeAnonimo = mb_substr($nome, 0, 1) . '***';

            $avaliacoes[] = [
                "rating" => (int)$row['rating'],
                "comentario" => $row['comment'] ?: null,
                "cliente" => $nomeAnonimo,
                "data" => $row['created_at']
            ];
        }

        return [
            "total" => $total,
            "media" => round((float)($stats['media'] ?? 0), 1),
            "distribuicao" => [
                5 => (int)($stats['r5'] ?? 0),
                4 => (int)($stats['r4'] ?? 0),
                3 => (int)($stats['r3'] ?? 0),
                2 => (int)($stats['r2'] ?? 0),
                1 => (int)($stats['r1'] ?? 0)
            ],
            "pagina" => $page,
            "por_pagina" => $limit,
            "avaliacoes" => $avaliacoes
        ];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Avaliacoes Listar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao listar avaliacoes", 500);
}
