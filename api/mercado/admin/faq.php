<?php
/**
 * GET/POST/DELETE /api/mercado/admin/faq.php
 * Admin FAQ management — uses om_support_faq table (shared with customer vitrine)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    // Table om_support_faq created via migration

    $method = $_SERVER['REQUEST_METHOD'];

    // GET — list all FAQs
    if ($method === 'GET') {
        $categoria = $_GET['category'] ?? null;

        $where = "1=1";
        $params = [];

        if ($categoria) {
            $where .= " AND categoria = ?";
            $params[] = $categoria;
        }

        $stmt = $db->prepare("
            SELECT id, categoria, pergunta, resposta, ativo, ordem, created_at, updated_at
            FROM om_support_faq
            WHERE $where
            ORDER BY ordem ASC, id ASC
        ");
        $stmt->execute($params);
        $faqs = $stmt->fetchAll();

        // Group by category
        $grouped = [];
        foreach ($faqs as $faq) {
            $cat = $faq['categoria'];
            if (!isset($grouped[$cat])) $grouped[$cat] = [];
            $grouped[$cat][] = $faq;
        }

        response(true, ['faqs' => $faqs, 'grouped' => $grouped]);
    }

    // POST — create or update FAQ
    if ($method === 'POST') {
        $input = getInput();
        $faqId = (int)($input['id'] ?? 0);
        $pergunta = strip_tags(trim($input['pergunta'] ?? $input['question'] ?? ''));
        $resposta = strip_tags(trim($input['resposta'] ?? $input['answer'] ?? ''));
        $categoria = strip_tags(trim($input['categoria'] ?? $input['category'] ?? 'geral'));
        $ativo = isset($input['ativo']) ? (bool)$input['ativo'] : true;
        $ordem = (int)($input['ordem'] ?? $input['sort_order'] ?? 0);

        if (empty($pergunta) || empty($resposta)) {
            response(false, null, "Pergunta e resposta obrigatorias", 400);
        }

        if ($faqId > 0) {
            $stmt = $db->prepare("
                UPDATE om_support_faq
                SET pergunta = ?, resposta = ?, categoria = ?, ativo = ?, ordem = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$pergunta, $resposta, $categoria, $ativo ? 1 : 0, $ordem, $faqId]);
            response(true, ['id' => $faqId], "FAQ atualizado");
        }

        $stmt = $db->prepare("
            INSERT INTO om_support_faq (pergunta, resposta, categoria, ativo, ordem)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$pergunta, $resposta, $categoria, $ativo ? 1 : 0, $ordem]);
        $newId = (int)$db->lastInsertId();

        response(true, ['id' => $newId], "FAQ criado");
    }

    // DELETE — remove FAQ
    if ($method === 'DELETE') {
        $faqId = (int)($_GET['id'] ?? 0);
        if (!$faqId) response(false, null, "ID obrigatorio", 400);

        $stmt = $db->prepare("DELETE FROM om_support_faq WHERE id = ?");
        $stmt->execute([$faqId]);

        response(true, ['message' => 'FAQ removido']);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/faq] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
