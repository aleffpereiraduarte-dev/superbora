<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) response(false, null, "Nao autorizado", 401);
    $pid = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("SELECT name, email, phone, address, city, state, cep, logo, delivery_fee, min_order, delivery_time_min, is_open as aberto, opens_at as horario_abertura, closes_at as horario_fechamento FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$pid]);
        response(true, ['config' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $allowed = ['name','phone','address','city','state','cep','delivery_fee','min_order','delivery_time_min','delivery_time_max','horario_abertura','horario_fechamento'];
        $sets = []; $vals = [];
        foreach ($allowed as $f) {
            if (isset($body[$f])) { $sets[] = "$f = ?"; $vals[] = $body[$f]; }
        }
        if ($sets) {
            $vals[] = $pid;
            $db->prepare("UPDATE om_market_partners SET " . implode(', ', $sets) . " WHERE partner_id = ?")->execute($vals);

            // Keep taxa_entrega/min_order_value in sync with the Portuguese duplicates
            $syncMap = ['delivery_fee' => 'taxa_entrega', 'min_order' => 'min_order_value'];
            foreach ($syncMap as $eng => $pt) {
                if (isset($body[$eng])) {
                    try { $db->prepare("UPDATE om_market_partners SET {$pt} = ? WHERE partner_id = ?")->execute([$body[$eng], $pid]); }
                    catch (Exception $e) { /* column may not exist */ }
                }
            }

            // Invalidate vitrine cache so app sees updated fee/hours within seconds
            try {
                require_once __DIR__ . '/../helpers/cache.php';
                // cachedQuery uses 'sbcache:' prefix via Redis OPT_PREFIX
                $r = new Redis();
                $r->connect('127.0.0.1', 6379, 0.5);
                $pwd = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '';
                if ($pwd) $r->auth($pwd);
                $iter = null;
                while ($keys = $r->scan($iter, 'sbcache:vitrine:*', 200)) {
                    foreach ($keys as $k) $r->del($k);
                }
                require_once __DIR__ . '/../helpers/r2-cache.php';
                if (function_exists('r2CacheInvalidatePartner')) r2CacheInvalidatePartner($pid);
            } catch (Exception $e) { /* non-critical */ }
        }
        response(true, ['updated' => true]);
    }
} catch (\Throwable $e) {
    error_log("[partner/configuracoes] " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
