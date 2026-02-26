<?php
/**
 * GET /api/mercado/shopper/achievements.php
 * Retorna XP, nivel, badges e progresso do shopper
 */
require_once __DIR__ . "/../config/auth.php";
try {
    $db = getDB(); $auth = requireShopperAuth(); $shopper_id = $auth["uid"];
    if ($_SERVER["REQUEST_METHOD"] !== "GET") { response(false, null, "Metodo nao permitido", 405); }
    $stmt = $db->prepare("SELECT achievement_key, level, progress, target, unlocked_at FROM om_shopper_achievements WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]); $achievements_rows = $stmt->fetchAll();
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE shopper_id = ? AND status = 'entregue'");
    $stmt->execute([$shopper_id]); $total_deliveries = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT rating FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]); $rating = floatval($stmt->fetchColumn() ?: 5.0);
    $xp = $total_deliveries * 10;
    $saved_achievements = [];
    foreach ($achievements_rows as $row) {
        $saved_achievements[$row["achievement_key"]] = $row;
    }
    $streak_days = 0;
    $stmt = $db->prepare("SELECT DISTINCT DATE(created_at) as dia FROM om_market_orders WHERE shopper_id = ? AND status = 'entregue' ORDER BY dia DESC LIMIT 60");
    $stmt->execute([$shopper_id]);
    $dias = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($dias)) { foreach ($dias as $i => $dia) { $expected = date('Y-m-d', strtotime("-{$i} days")); if ($dia === $expected) { $streak_days++; } else { break; } } }
    $level = "Iniciante";
    $levels = [
        ["name" => "Iniciante", "min_xp" => 0, "max_xp" => 99],
        ["name" => "Intermediario", "min_xp" => 100, "max_xp" => 499],
        ["name" => "Experiente", "min_xp" => 500, "max_xp" => 1499],
        ["name" => "Profissional", "min_xp" => 1500, "max_xp" => 4999],
        ["name" => "Elite", "min_xp" => 5000, "max_xp" => 99999]
    ];
    $current_level = $levels[0]; $next_level = $levels[1] ?? null;
    foreach ($levels as $i => $lv) {
        if ($xp >= $lv["min_xp"]) { $current_level = $lv; $next_level = $levels[$i + 1] ?? null; $level = $lv["name"]; }
    }
    $progress = 0;
    if ($next_level) {
        $range = $next_level["min_xp"] - $current_level["min_xp"];
        $progress = $range > 0 ? round(($xp - $current_level["min_xp"]) / $range * 100, 1) : 100;
    } else { $progress = 100; }
    $all_badges = [
        ["id" => "primeira_entrega", "name" => "Primeira Entrega", "description" => "Completou sua primeira entrega", "icon" => "delivery", "threshold" => 1, "field" => "deliveries"],
        ["id" => "entregador_10", "name" => "Entregador Dedicado", "description" => "Completou 10 entregas", "icon" => "star", "threshold" => 10, "field" => "deliveries"],
        ["id" => "veterano_50", "name" => "Veterano", "description" => "Completou 50 entregas", "icon" => "medal", "threshold" => 50, "field" => "deliveries"],
        ["id" => "centenario", "name" => "Centenario", "description" => "Completou 100 entregas", "icon" => "trophy", "threshold" => 100, "field" => "deliveries"],
        ["id" => "lenda_500", "name" => "Lenda", "description" => "Completou 500 entregas", "icon" => "crown", "threshold" => 500, "field" => "deliveries"],
        ["id" => "avaliacao_perfeita", "name" => "Avaliacao Perfeita", "description" => "Manteve avaliacao 5.0", "icon" => "sparkle", "threshold" => 5.0, "field" => "rating"],
        ["id" => "streak_7", "name" => "Semana Perfeita", "description" => "7 dias consecutivos trabalhando", "icon" => "fire", "threshold" => 7, "field" => "streak"],
        ["id" => "streak_30", "name" => "Maquina de Entregas", "description" => "30 dias consecutivos trabalhando", "icon" => "rocket", "threshold" => 30, "field" => "streak"]
    ];
    $badges = [];
    foreach ($all_badges as $badge) {
        $value = 0;
        if ($badge["field"] === "deliveries") $value = $total_deliveries;
        elseif ($badge["field"] === "rating") $value = $rating;
        elseif ($badge["field"] === "streak") $value = $streak_days;
        $earned = $value >= $badge["threshold"];
        $badges[] = ["id" => $badge["id"], "name" => $badge["name"], "description" => $badge["description"], "icon" => $badge["icon"], "earned" => $earned, "progress" => min(100, round($value / $badge["threshold"] * 100, 1))];
    }
    if ($total_deliveries > 0) {
        $badges_earned = array_filter($badges, function($b) { return $b["earned"]; });
        foreach ($badges_earned as $b) {
            $threshold = 0;
            foreach ($all_badges as $ab) { if ($ab["id"] === $b["id"]) { $threshold = $ab["threshold"]; break; } }
            $stmt = $db->prepare("INSERT INTO om_shopper_achievements (shopper_id, achievement_key, level, progress, target, unlocked_at, created_at) VALUES (?, ?, 1, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE progress = VALUES(progress), level = VALUES(level)");
            $stmt->execute([$shopper_id, $b["id"], $threshold, $threshold]);
        }
    }
    $earned_count = count(array_filter($badges, function($b) { return $b["earned"]; }));
    response(true, ["xp" => $xp, "level" => $level, "next_level" => $next_level ? $next_level["name"] : null, "next_level_xp" => $next_level ? $next_level["min_xp"] : null, "progress_to_next_level" => $progress, "total_deliveries" => $total_deliveries, "rating" => $rating, "streak_days" => $streak_days, "badges" => $badges, "badges_earned" => $earned_count, "badges_total" => count($all_badges)], "Conquistas carregadas");
} catch (Exception $e) { error_log("[shopper/achievements] Erro: " . $e->getMessage()); response(false, null, "Erro ao carregar conquistas", 500); }
