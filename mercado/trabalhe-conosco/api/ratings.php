<?php
/**
 * API: Avaliações
 * GET /api/ratings.php - Obter avaliações recebidas
 * POST /api/ratings.php - Avaliar cliente/pedido
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Obter avaliações
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Média e total
        $stmt = $db->prepare("
            SELECT 
                AVG(rating) as average_rating,
                COUNT(*) as total_ratings,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_stars,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_stars,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_stars,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_stars,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
            FROM " . table('ratings') . "
            WHERE worker_id = ? AND type = 'from_customer'
        ");
        $stmt->execute([$workerId]);
        $summary = $stmt->fetch();

        // Últimas avaliações
        $stmt = $db->prepare("
            SELECT r.rating, r.comment, r.created_at, o.order_number
            FROM " . table('ratings') . " r
            LEFT JOIN " . table('orders') . " o ON o.id = r.order_id
            WHERE r.worker_id = ? AND r.type = 'from_customer'
            ORDER BY r.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$workerId]);
        $ratings = $stmt->fetchAll();

        // Tags mais recebidas
        $stmt = $db->prepare("
            SELECT tag, COUNT(*) as count
            FROM " . table('rating_tags') . "
            WHERE worker_id = ?
            GROUP BY tag
            ORDER BY count DESC
            LIMIT 10
        ");
        $stmt->execute([$workerId]);
        $tags = $stmt->fetchAll();

        jsonSuccess([
            'summary' => $summary,
            'ratings' => $ratings,
            'tags' => $tags
        ]);

    } catch (Exception $e) {
        error_log("Ratings GET error: " . $e->getMessage());
        jsonError('Erro ao buscar avaliações', 500);
    }
}

// POST - Avaliar cliente/pedido
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();

    $orderId = $input['order_id'] ?? null;
    $rating = intval($input['rating'] ?? 0);
    $comment = trim($input['comment'] ?? '');
    $tags = $input['tags'] ?? [];

    if (!$orderId) {
        jsonError('ID do pedido é obrigatório');
    }

    if ($rating < 1 || $rating > 5) {
        jsonError('Avaliação deve ser entre 1 e 5');
    }

    try {
        // Verificar se pedido existe e pertence ao trabalhador
        $stmt = $db->prepare("
            SELECT id, customer_id FROM " . table('orders') . "
            WHERE id = ? AND worker_id = ? AND status = 'completed'
        ");
        $stmt->execute([$orderId, $workerId]);
        $order = $stmt->fetch();

        if (!$order) {
            jsonError('Pedido não encontrado', 404);
        }

        // Verificar se já avaliou
        $stmt = $db->prepare("
            SELECT id FROM " . table('ratings') . "
            WHERE order_id = ? AND worker_id = ? AND type = 'from_worker'
        ");
        $stmt->execute([$orderId, $workerId]);
        if ($stmt->fetch()) {
            jsonError('Você já avaliou este pedido');
        }

        $db->beginTransaction();

        // Inserir avaliação
        $stmt = $db->prepare("
            INSERT INTO " . table('ratings') . "
            (order_id, worker_id, customer_id, type, rating, comment, created_at)
            VALUES (?, ?, ?, 'from_worker', ?, ?, NOW())
        ");
        $stmt->execute([$orderId, $workerId, $order['customer_id'], $rating, $comment]);
        $ratingId = $db->lastInsertId();

        // Inserir tags
        if (!empty($tags)) {
            $stmt = $db->prepare("
                INSERT INTO " . table('rating_tags') . " (rating_id, worker_id, customer_id, tag)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($tags as $tag) {
                $stmt->execute([$ratingId, $workerId, $order['customer_id'], $tag]);
            }
        }

        // Atualizar média do cliente
        $stmt = $db->prepare("
            UPDATE oc_customer 
            SET rating = (
                SELECT AVG(rating) FROM " . table('ratings') . " 
                WHERE customer_id = ? AND type = 'from_worker'
            )
            WHERE customer_id = ?
        ");
        $stmt->execute([$order['customer_id'], $order['customer_id']]);

        $db->commit();

        jsonSuccess([], 'Avaliação enviada com sucesso');

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Ratings POST error: " . $e->getMessage());
        jsonError('Erro ao enviar avaliação', 500);
    }
}

jsonError('Método não permitido', 405);
