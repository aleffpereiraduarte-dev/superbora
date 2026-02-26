<?php
/**
 * GET /api/mercado/pricing/surge.php
 * Returns current surge multiplier based on demand vs supply
 *
 * Query: ?area=centro (optional)
 *
 * Logic:
 * - Counts active orders vs available shoppers
 * - Ratio > 2:1 => 1.5x, > 4:1 => 2.0x, > 6:1 => 2.5x
 * - Weekday lunch (11-14h) and dinner (18-22h) get +0.2 base bonus
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=60');

try {
    $area = trim($_GET["area"] ?? "");

    $cacheKey = "surge_pricing_" . ($area ?: "global");

    $data = CacheHelper::remember($cacheKey, 60, function() use ($area) {
        $db = getDB();

        // Check if surge pricing is enabled in config
        $stmt = $db->prepare("SELECT valor FROM om_config WHERE chave = 'surge_enabled'");
        $stmt->execute();
        $cfg = $stmt->fetch();
        $enabled = $cfg ? (int)$cfg['valor'] : 1; // enabled by default

        if (!$enabled) {
            return [
                'multiplier' => 1.0,
                'surge_active' => false,
                'reason' => null,
                'active_orders' => 0,
                'available_shoppers' => 0,
            ];
        }

        // Load thresholds from config (or use defaults)
        $stmt = $db->prepare("SELECT valor FROM om_config WHERE chave = 'surge_thresholds'");
        $stmt->execute();
        $thresholdsCfg = $stmt->fetch();
        $thresholds = $thresholdsCfg ? json_decode($thresholdsCfg['valor'], true) : null;

        if (!$thresholds || !is_array($thresholds)) {
            $thresholds = [
                ['ratio' => 2, 'multiplier' => 1.5],
                ['ratio' => 4, 'multiplier' => 2.0],
                ['ratio' => 6, 'multiplier' => 2.5],
            ];
        }

        // Count active orders and available shoppers
        $sql = "SELECT
            (SELECT COUNT(*) FROM om_market_orders WHERE status IN ('pending','pendente','aceito','preparando','pronto') AND DATE(date_added) = CURRENT_DATE) as active_orders,
            (SELECT COUNT(*) FROM om_market_shoppers WHERE disponivel = 1 AND is_online = 1) as available_shoppers";

        $stmt = $db->query($sql);
        $load = $stmt->fetch();

        $activeOrders = (int)$load['active_orders'];
        $availableShoppers = max((int)$load['available_shoppers'], 1); // avoid division by zero

        $ratio = $activeOrders / $availableShoppers;

        // Determine surge multiplier from thresholds (highest matching)
        $multiplier = 1.0;
        $reason = null;

        // Sort thresholds descending by ratio to find the highest match
        usort($thresholds, function($a, $b) { return $b['ratio'] - $a['ratio']; });
        foreach ($thresholds as $t) {
            if ($ratio > $t['ratio']) {
                $multiplier = (float)$t['multiplier'];
                $reason = "Alta demanda ({$activeOrders} pedidos / {$availableShoppers} shoppers)";
                break;
            }
        }

        // Time-based bonus: weekday lunch (11-14) and dinner (18-22)
        $hour = (int)date('G');
        $dayOfWeek = (int)date('N'); // 1=Mon, 7=Sun
        $timeBonus = 0;

        if ($dayOfWeek <= 5) { // weekdays
            if ($hour >= 11 && $hour < 14) {
                $timeBonus = 0.2;
            } elseif ($hour >= 18 && $hour < 22) {
                $timeBonus = 0.2;
            }
        }

        if ($timeBonus > 0) {
            $multiplier += $timeBonus;
            if (!$reason) {
                $reason = "Horario de pico";
            } else {
                $reason .= " + horario de pico";
            }
        }

        // Cap at max configured multiplier
        $stmt = $db->prepare("SELECT valor FROM om_config WHERE chave = 'surge_max_multiplier'");
        $stmt->execute();
        $maxCfg = $stmt->fetch();
        $maxMultiplier = $maxCfg ? (float)$maxCfg['valor'] : 3.0;
        $multiplier = min($multiplier, $maxMultiplier);

        // Round to 1 decimal
        $multiplier = round($multiplier, 1);

        return [
            'multiplier' => $multiplier,
            'surge_active' => $multiplier > 1.0,
            'reason' => $multiplier > 1.0 ? $reason : null,
            'active_orders' => $activeOrders,
            'available_shoppers' => $availableShoppers,
            'time_bonus' => $timeBonus,
        ];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Surge Pricing] Erro: " . $e->getMessage());
    response(false, null, "Erro ao calcular taxa dinamica", 500);
}
