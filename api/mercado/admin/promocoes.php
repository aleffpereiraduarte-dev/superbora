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
    $admin_id = $payload["uid"];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $page = max(1, (int)($_GET["page"] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_promotions");
        $total = (int)$stmt->fetch()["total"];

        $stmt = $db->prepare("
            SELECT * FROM om_market_promotions
            ORDER BY start_date DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([(int)$limit, (int)$offset]);
        $promocoes = $stmt->fetchAll();

        response(true, [
            "promocoes" => $promocoes,
            "pagination" => ["page" => $page, "limit" => $limit, "total" => $total, "pages" => ceil($total / $limit)]
        ], "Promocoes listadas");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $title = strip_tags(trim($input["title"] ?? ""));
        $description = strip_tags(trim($input["description"] ?? ""));
        $type = $input["type"] ?? "bonus";
        $bonus_amount = (float)($input["bonus_amount"] ?? 0);
        $start_date = $input["start_date"] ?? date("Y-m-d H:i:s");
        $end_date = $input["end_date"] ?? date("Y-m-d H:i:s", strtotime("+7 days"));
        $status = $input["status"] ?? "active";

        if (!$title) response(false, null, "title obrigatorio", 400);

        // Validate type
        $valid_types = ['bonus', 'discount', 'frete_gratis', 'cashback'];
        if (!in_array($type, $valid_types, true)) {
            response(false, null, "Tipo invalido. Permitidos: " . implode(', ', $valid_types), 400);
        }

        // Validate status
        $valid_statuses = ['active', 'inactive', 'scheduled', 'expired'];
        if (!in_array($status, $valid_statuses, true)) {
            response(false, null, "Status invalido. Permitidos: " . implode(', ', $valid_statuses), 400);
        }

        // Validate bonus_amount >= 0
        if ($bonus_amount < 0) {
            response(false, null, "bonus_amount deve ser >= 0", 400);
        }

        // Validate date formats
        if (!empty($input["start_date"])) {
            $d = DateTime::createFromFormat('Y-m-d H:i:s', $start_date) ?: DateTime::createFromFormat('Y-m-d', $start_date);
            if (!$d) response(false, null, "start_date formato invalido (YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS)", 400);
        }
        if (!empty($input["end_date"])) {
            $d = DateTime::createFromFormat('Y-m-d H:i:s', $end_date) ?: DateTime::createFromFormat('Y-m-d', $end_date);
            if (!$d) response(false, null, "end_date formato invalido (YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS)", 400);
        }

        $stmt = $db->prepare("INSERT INTO om_market_promotions (title, description, type, bonus_amount, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description, $type, $bonus_amount, $start_date, $end_date, $status]);
        $new_id = (int)$db->lastInsertId();

        response(true, ["id" => $new_id], "Promocao criada");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/promocoes] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
