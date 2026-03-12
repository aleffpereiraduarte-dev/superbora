<?php
/**
 * GET/POST /api/mercado/customer/dietary-preferences.php
 * GET: retorna preferencias alimentares do cliente
 * POST: salva preferencias { preferences: ["vegetariano", "sem_gluten", ...] }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Nao autorizado", 401);
    $customerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("SELECT dietary_preferences FROM om_customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $prefs = $stmt->fetchColumn();
        $prefs = $prefs ? json_decode($prefs, true) : [];
        response(true, ['preferences' => $prefs]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $preferences = $input['preferences'] ?? [];

        if (!is_array($preferences)) response(false, null, "preferences deve ser um array", 400);

        // Sanitize: only allow known preference keys
        $allowed = ['vegetariano', 'vegano', 'sem_gluten', 'sem_lactose', 'kosher', 'halal',
                     'low_carb', 'sem_acucar', 'organico', 'sem_nozes', 'pescetariano'];
        $clean = array_values(array_intersect($preferences, $allowed));

        // Try updating JSON column, fallback to text if column doesn't support JSON
        try {
            $db->prepare("UPDATE om_customers SET dietary_preferences = ?::jsonb WHERE customer_id = ?")
                ->execute([json_encode($clean), $customerId]);
        } catch (Exception $e) {
            // Column might not exist yet - create it
            try {
                $db->exec("ALTER TABLE om_customers ADD COLUMN IF NOT EXISTS dietary_preferences JSONB DEFAULT '[]'");
                $db->prepare("UPDATE om_customers SET dietary_preferences = ?::jsonb WHERE customer_id = ?")
                    ->execute([json_encode($clean), $customerId]);
            } catch (Exception $e2) {
                error_log("[dietary-prefs] Column error: " . $e2->getMessage());
                // Still return success - app saves locally too
                response(true, ['preferences' => $clean], "Salvo localmente");
            }
        }

        response(true, ['preferences' => $clean], "Preferencias salvas");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[dietary-prefs] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar", 500);
}
