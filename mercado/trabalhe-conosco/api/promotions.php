<?php
/**
 * API: Promoções e Desafios
 * GET /api/promotions.php - Listar promoções ativas
 * GET /api/promotions.php?type=challenges - Listar desafios
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Listar promoções/desafios
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = $_GET['type'] ?? 'promotions';

    try {
        if ($type === 'challenges') {
            // Desafios ativos
            $stmt = $db->prepare("
                SELECT 
                    c.id, c.title, c.description, c.type, c.target, c.reward,
                    c.starts_at, c.ends_at, c.badge_icon,
                    COALESCE(wp.progress, 0) as progress,
                    COALESCE(wp.completed, 0) as completed
                FROM " . table('challenges') . " c
                LEFT JOIN " . table('worker_progress') . " wp ON wp.challenge_id = c.id AND wp.worker_id = ?
                WHERE c.is_active = 1 AND c.ends_at > NOW()
                ORDER BY c.reward DESC
            ");
            $stmt->execute([$workerId]);
            $challenges = $stmt->fetchAll();

            // Badges conquistados
            $stmt = $db->prepare("
                SELECT b.id, b.name, b.icon, b.description, wb.earned_at
                FROM " . table('badges') . " b
                INNER JOIN " . table('worker_badges') . " wb ON wb.badge_id = b.id
                WHERE wb.worker_id = ?
                ORDER BY wb.earned_at DESC
            ");
            $stmt->execute([$workerId]);
            $badges = $stmt->fetchAll();

            jsonSuccess([
                'challenges' => $challenges,
                'badges' => $badges
            ]);
        }

        // Promoções ativas
        $stmt = $db->prepare("
            SELECT 
                p.id, p.title, p.description, p.type, p.value, 
                p.multiplier, p.min_orders, p.zone_id,
                p.starts_at, p.ends_at,
                z.name as zone_name
            FROM " . table('promotions') . " p
            LEFT JOIN " . table('zones') . " z ON z.id = p.zone_id
            WHERE p.is_active = 1 
            AND (p.starts_at IS NULL OR p.starts_at <= NOW())
            AND (p.ends_at IS NULL OR p.ends_at > NOW())
            ORDER BY p.value DESC
        ");
        $stmt->execute();
        $promotions = $stmt->fetchAll();

        // Promoções futuras
        $stmt = $db->prepare("
            SELECT id, title, description, type, value, starts_at, ends_at
            FROM " . table('promotions') . "
            WHERE is_active = 1 AND starts_at > NOW()
            ORDER BY starts_at ASC
            LIMIT 5
        ");
        $stmt->execute();
        $upcoming = $stmt->fetchAll();

        // Histórico de bônus recebidos
        $stmt = $db->prepare("
            SELECT type, amount, description, created_at
            FROM " . table('wallet_transactions') . "
            WHERE worker_id = ? AND type = 'bonus'
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$workerId]);
        $bonusHistory = $stmt->fetchAll();

        // Total de bônus este mês
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM " . table('wallet_transactions') . "
            WHERE worker_id = ? AND type = 'bonus' AND MONTH(created_at) = MONTH(NOW())
        ");
        $stmt->execute([$workerId]);
        $monthlyBonus = $stmt->fetch();

        jsonSuccess([
            'promotions' => $promotions,
            'upcoming' => $upcoming,
            'bonus_history' => $bonusHistory,
            'monthly_bonus' => $monthlyBonus['total']
        ]);

    } catch (Exception $e) {
        error_log("Promotions error: " . $e->getMessage());
        jsonError('Erro ao buscar promoções', 500);
    }
}

jsonError('Método não permitido', 405);
