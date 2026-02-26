<?php
/**
 * GET/POST/DELETE /api/mercado/admin/quick-replies.php
 * CRUD for canned/quick reply templates used by support agents
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    // FIX: Only CREATE TABLE when table doesn't exist, not on every request
    $check = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'om_quick_replies')");
    if (!$check->fetchColumn()) {
        $db->exec("CREATE TABLE IF NOT EXISTS om_quick_replies (
            id SERIAL PRIMARY KEY,
            titulo VARCHAR(100) NOT NULL,
            mensagem TEXT NOT NULL,
            categoria VARCHAR(50) DEFAULT 'geral',
            atalho VARCHAR(30),
            ativo BOOLEAN DEFAULT true,
            uso_count INT DEFAULT 0,
            created_by INT,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )");
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // GET — list
    if ($method === 'GET') {
        $categoria = $_GET['categoria'] ?? null;

        $where = "1=1";
        $params = [];
        if ($categoria) {
            // FIX: Enforce length limit on categoria filter
            if (mb_strlen($categoria) > 50) {
                response(false, null, "Categoria invalida", 400);
            }
            $where .= " AND categoria = ?";
            $params[] = $categoria;
        }

        $stmt = $db->prepare("
            SELECT * FROM om_quick_replies
            WHERE $where
            ORDER BY uso_count DESC, titulo ASC
        ");
        $stmt->execute($params);
        $replies = $stmt->fetchAll();

        // Group by category
        $grouped = [];
        foreach ($replies as $r) {
            $cat = $r['categoria'] ?? 'geral';
            if (!isset($grouped[$cat])) $grouped[$cat] = [];
            $grouped[$cat][] = $r;
        }

        response(true, [
            'replies' => $replies,
            'grouped' => $grouped,
            'categories' => array_keys($grouped)
        ]);
    }

    // POST — create/update + increment usage
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'save';

        // Track usage
        if ($action === 'use') {
            $id = (int)($input['id'] ?? 0);
            if (!$id) response(false, null, "id obrigatorio", 400);
            $db->prepare("UPDATE om_quick_replies SET uso_count = uso_count + 1 WHERE id = ?")->execute([$id]);
            response(true, ['message' => 'Uso registrado']);
        }

        // Create or update
        $id = (int)($input['id'] ?? 0);
        $titulo = trim($input['titulo'] ?? '');
        $mensagem = trim($input['mensagem'] ?? '');
        $categoria = trim($input['categoria'] ?? 'geral');
        $atalho = trim($input['atalho'] ?? '');
        $ativo = (bool)($input['ativo'] ?? true);

        if (empty($titulo) || empty($mensagem)) {
            response(false, null, "titulo e mensagem obrigatorios", 400);
        }

        // FIX: Enforce length limits matching VARCHAR constraints
        if (mb_strlen($titulo) > 100) {
            response(false, null, "Titulo muito longo (max 100 caracteres)", 400);
        }
        if (mb_strlen($mensagem) > 5000) {
            response(false, null, "Mensagem muito longa (max 5000 caracteres)", 400);
        }
        if (mb_strlen($categoria) > 50) {
            response(false, null, "Categoria muito longa (max 50 caracteres)", 400);
        }
        if (mb_strlen($atalho) > 30) {
            response(false, null, "Atalho muito longo (max 30 caracteres)", 400);
        }

        if ($id) {
            $db->prepare("
                UPDATE om_quick_replies SET titulo=?, mensagem=?, categoria=?, atalho=?, ativo=?::boolean, updated_at=NOW()
                WHERE id=?
            ")->execute([$titulo, $mensagem, $categoria, $atalho ?: null, $ativo ? 'true' : 'false', $id]);
            response(true, ['id' => $id, 'message' => 'Resposta atualizada']);
        } else {
            $stmt = $db->prepare("
                INSERT INTO om_quick_replies (titulo, mensagem, categoria, atalho, ativo, created_by)
                VALUES (?, ?, ?, ?, ?::boolean, ?) RETURNING id
            ");
            $stmt->execute([$titulo, $mensagem, $categoria, $atalho ?: null, $ativo ? 'true' : 'false', $adminId]);
            $newId = (int)$stmt->fetchColumn();
            response(true, ['id' => $newId, 'message' => 'Resposta criada']);
        }
    }

    // DELETE
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) response(false, null, "id obrigatorio", 400);
        $db->prepare("DELETE FROM om_quick_replies WHERE id = ?")->execute([$id]);
        response(true, ['message' => 'Resposta removida']);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/quick-replies] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
