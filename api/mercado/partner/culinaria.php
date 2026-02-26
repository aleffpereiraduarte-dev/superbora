<?php
/**
 * /api/mercado/partner/culinaria.php
 * Gerenciar tags de culinaria do parceiro
 *
 * GET                    - Listar todas as tags disponiveis (com flag se parceiro tem)
 * POST action=set_tags   - Definir tags do parceiro { tag_ids: [1,3,5] }
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
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("
            SELECT t.id, t.name, t.slug, t.icon, t.image,
                   CASE WHEN ptl.partner_id IS NOT NULL THEN 1 ELSE 0 END AS selected
            FROM om_partner_tags t
            LEFT JOIN om_partner_tag_links ptl ON ptl.tag_id = t.id AND ptl.partner_id = ?
            WHERE t.active = 1
            ORDER BY t.sort_order
        ");
        $stmt->execute([$partnerId]);
        $tags = $stmt->fetchAll();

        foreach ($tags as &$t) {
            $t['id'] = (int)$t['id'];
            $t['selected'] = (bool)$t['selected'];
        }
        unset($t);

        response(true, ['tags' => $tags]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $action = $input['action'] ?? '';

    if ($action === 'set_tags') {
        $tagIds = $input['tag_ids'] ?? [];
        if (!is_array($tagIds)) {
            response(false, null, "tag_ids deve ser um array", 400);
        }

        $db->prepare("DELETE FROM om_partner_tag_links WHERE partner_id = ?")->execute([$partnerId]);

        if (count($tagIds) > 0) {
            $stmt = $db->prepare("INSERT INTO om_partner_tag_links (partner_id, tag_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            foreach ($tagIds as $tid) {
                $tid = (int)$tid;
                if ($tid > 0) {
                    $stmt->execute([$partnerId, $tid]);
                }
            }
        }

        response(true, null, "Tags de culinaria atualizadas");
    }

    response(false, null, "action obrigatorio", 400);

} catch (Exception $e) {
    error_log("[culinaria] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar", 500);
}
