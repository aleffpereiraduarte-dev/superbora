<?php
/**
 * POST /api/mercado/painel/toggle-orders.php
 * Toggle "Aceitar Pedidos" para o mercado logado
 *
 * Body options:
 *   { "accepting": 0|1 }                    — open/close
 *   { "action": "pause", "minutes": 30 }    — pause for N minutes
 *   { "action": "open" }                     — reopen (clear pause)
 *   { "action": "close" }                    — close store
 */
session_start();
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/csrf.php";
verifyCsrf();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Método não permitido", 405);
}

// Verificar sessão do parceiro
$partner_id = $_SESSION['mercado_id'] ?? $_SESSION['mp_id'] ?? null;
if (!$partner_id) {
    response(false, null, "Não autenticado", 401);
}

try {
    $input = getInput();
    $db = getDB();

    // New action-based API (pause/open/close)
    $action = $input['action'] ?? null;

    if ($action !== null) {
        switch ($action) {
            case 'pause':
                $minutes = (int)($input['minutes'] ?? 0);
                if ($minutes < 1 || $minutes > 480) {
                    response(false, null, "Tempo de pausa inválido (1-480 minutos).", 400);
                }
                $pause_until = date('Y-m-d H:i:s', time() + ($minutes * 60));
                $stmt = $db->prepare("UPDATE om_market_partners SET is_open = 0, pause_until = ?, pause_reason = ? WHERE partner_id = ?");
                $stmt->execute([$pause_until, 'Pausa temporária', $partner_id]);

                response(true, [
                    "is_open" => 0,
                    "pause_until" => $pause_until,
                    "partner_id" => (int)$partner_id
                ], "Loja pausada por {$minutes} minutos");
                break;

            case 'open':
                $stmt = $db->prepare("UPDATE om_market_partners SET is_open = 1, pause_until = NULL, pause_reason = NULL WHERE partner_id = ?");
                $stmt->execute([$partner_id]);

                response(true, [
                    "is_open" => 1,
                    "pause_until" => null,
                    "partner_id" => (int)$partner_id
                ], "Loja aberta para pedidos");
                break;

            case 'close':
                $stmt = $db->prepare("UPDATE om_market_partners SET is_open = 0, pause_until = NULL, pause_reason = NULL WHERE partner_id = ?");
                $stmt->execute([$partner_id]);

                response(true, [
                    "is_open" => 0,
                    "pause_until" => null,
                    "partner_id" => (int)$partner_id
                ], "Loja fechada para pedidos");
                break;

            default:
                response(false, null, "Ação inválida. Use 'pause', 'open' ou 'close'.", 400);
        }
    }

    // Legacy accepting-based API (backwards compatible)
    $accepting = isset($input['accepting']) ? (int)$input['accepting'] : null;

    if ($accepting === null || !in_array($accepting, [0, 1], true)) {
        response(false, null, "Parâmetro 'accepting' inválido. Use 0 ou 1.", 400);
    }

    // When opening, also clear any active pause
    if ($accepting === 1) {
        $stmt = $db->prepare("UPDATE om_market_partners SET is_open = 1, pause_until = NULL, pause_reason = NULL WHERE partner_id = ?");
    } else {
        $stmt = $db->prepare("UPDATE om_market_partners SET is_open = 0 WHERE partner_id = ?");
    }
    $stmt->execute([$partner_id]);

    if ($stmt->rowCount() === 0) {
        // Verificar se o parceiro existe
        $check = $db->prepare("SELECT partner_id FROM om_market_partners WHERE partner_id = ?");
        $check->execute([$partner_id]);
        if (!$check->fetch()) {
            response(false, null, "Parceiro não encontrado", 404);
        }
    }

    response(true, [
        "is_open" => $accepting,
        "partner_id" => (int)$partner_id
    ], $accepting ? "Loja aberta para pedidos" : "Loja fechada para pedidos");

} catch (Exception $e) {
    error_log("[API Toggle Orders] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar status. Tente novamente.", 500);
}
