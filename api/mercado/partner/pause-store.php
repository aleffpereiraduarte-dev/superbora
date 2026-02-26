<?php
/**
 * POST /api/mercado/partner/pause-store.php
 * Pause/unpause the store with optional duration
 *
 * POST Body:
 *   { "action": "pause", "duration_minutes": 30 }   - Pause for 30 minutes
 *   { "action": "pause", "duration_minutes": 0 }    - Pause indefinitely
 *   { "action": "resume" }                           - Resume (reopen)
 *   GET: returns current pause status
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/cache.php";
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

    // Ensure pause columns exist (safe to run multiple times)
    try {
        $db->exec("ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS pause_until TIMESTAMP DEFAULT NULL");
        $db->exec("ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS pause_reason VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {
        // Columns may already exist, ignore
    }

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        // Return current pause status
        $stmt = $db->prepare("SELECT is_open, pause_until, pause_reason FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $row = $stmt->fetch();

        if (!$row) {
            response(false, null, "Parceiro nao encontrado", 404);
        }

        $isPaused = !$row['is_open'] && !empty($row['pause_until']);
        $pauseUntil = $row['pause_until'];
        $remainingMinutes = 0;

        if ($isPaused && $pauseUntil) {
            $now = new DateTime();
            $until = new DateTime($pauseUntil);
            if ($until > $now) {
                $diff = $now->diff($until);
                $remainingMinutes = ($diff->h * 60) + $diff->i + ($diff->s > 0 ? 1 : 0);
            } else {
                // Pause expired, auto-reopen
                $stmt2 = $db->prepare("UPDATE om_market_partners SET is_open = 1, pause_until = NULL, pause_reason = NULL, updated_at = NOW() WHERE partner_id = ?");
                $stmt2->execute([$partnerId]);
                $isPaused = false;
                $remainingMinutes = 0;

                om_cache()->flush('store_');
                om_cache()->flush('admin_');
            }
        }

        response(true, [
            "is_open" => (bool)$row['is_open'],
            "is_paused" => $isPaused,
            "pause_until" => $pauseUntil,
            "pause_reason" => $row['pause_reason'],
            "remaining_minutes" => $remainingMinutes,
        ]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $action = trim($input['action'] ?? '');

    if ($action === 'pause') {
        $durationMinutes = (int)($input['duration_minutes'] ?? 0);
        $reason = trim($input['reason'] ?? 'Pausado pelo parceiro');

        $pauseUntil = null;
        if ($durationMinutes > 0) {
            $until = new DateTime();
            $until->modify("+{$durationMinutes} minutes");
            $pauseUntil = $until->format('Y-m-d H:i:s');
        }

        $stmt = $db->prepare("
            UPDATE om_market_partners
            SET is_open = 0, pause_until = ?, pause_reason = ?, updated_at = NOW()
            WHERE partner_id = ?
        ");
        $stmt->execute([$pauseUntil, $reason, $partnerId]);

        om_cache()->flush('store_');
        om_cache()->flush('admin_');

        response(true, [
            "is_open" => false,
            "is_paused" => true,
            "pause_until" => $pauseUntil,
            "duration_minutes" => $durationMinutes,
            "message" => $durationMinutes > 0
                ? "Loja pausada por {$durationMinutes} minutos"
                : "Loja pausada indefinidamente"
        ]);
    } elseif ($action === 'resume') {
        $stmt = $db->prepare("
            UPDATE om_market_partners
            SET is_open = 1, pause_until = NULL, pause_reason = NULL, updated_at = NOW()
            WHERE partner_id = ?
        ");
        $stmt->execute([$partnerId]);

        om_cache()->flush('store_');
        om_cache()->flush('admin_');

        response(true, [
            "is_open" => true,
            "is_paused" => false,
            "message" => "Loja reaberta com sucesso"
        ]);
    } else {
        response(false, null, "Acao invalida. Use 'pause' ou 'resume'.", 400);
    }

} catch (Exception $e) {
    error_log("[partner/pause-store] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar status da loja", 500);
}
