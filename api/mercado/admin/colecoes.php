<?php
/**
 * Admin Collections Management
 * GET - List all collections (including inactive)
 * POST - Create/update/delete collections and manage items
 */

require_once __DIR__ . '/../config/database.php';
setCorsHeaders();

$auth = require_once __DIR__ . '/../config/auth.php';
om_auth()->requireAdmin();

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);

    if ($id) {
        // Single collection with items
        $stmt = $db->prepare("SELECT * FROM om_market_colecoes WHERE id = ?");
        $stmt->execute([$id]);
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$col) response(false, null, 'Coleção não encontrada', 404);

        // Fetch items
        $stmt = $db->prepare("
            SELECT ci.id as item_ref_id, ci.item_id, ci.tipo, ci.posicao,
                   CASE WHEN ci.tipo = 'produto' THEN p.name
                        WHEN ci.tipo = 'loja' THEN s.name END as name,
                   CASE WHEN ci.tipo = 'produto' THEN p.image_url
                        WHEN ci.tipo = 'loja' THEN s.logo_url END as image_url,
                   CASE WHEN ci.tipo = 'produto' THEN p.price END as price
            FROM om_market_colecao_items ci
            LEFT JOIN om_market_products p ON p.id = ci.item_id AND ci.tipo = 'produto'
            LEFT JOIN om_market_stores s ON s.id = ci.item_id AND ci.tipo = 'loja'
            WHERE ci.colecao_id = ?
            ORDER BY ci.posicao ASC
        ");
        $stmt->execute([$id]);
        $col['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, $col);
    }

    // List all
    $stmt = $db->prepare("
        SELECT c.*,
               (SELECT COUNT(*) FROM om_market_colecao_items ci WHERE ci.colecao_id = c.id) as total_items
        FROM om_market_colecoes c
        ORDER BY c.posicao ASC, c.created_at DESC
    ");
    $stmt->execute();
    response(true, ['collections' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    $input = getInput();
    $action = $input['action'] ?? 'create';

    if ($action === 'create') {
        $titulo = trim($input['titulo'] ?? '');
        if (!$titulo) response(false, null, 'Título obrigatório', 400);

        $slug = $input['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($titulo));

        $stmt = $db->prepare("
            INSERT INTO om_market_colecoes (titulo, subtitulo, descricao, slug, tipo, imagem_url, cor_fundo, cor_texto, icone, posicao, destaque, data_inicio, data_fim)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $titulo,
            $input['subtitulo'] ?? null,
            $input['descricao'] ?? null,
            $slug,
            $input['tipo'] ?? 'produtos',
            $input['imagem_url'] ?? null,
            $input['cor_fundo'] ?? '#FFFFFF',
            $input['cor_texto'] ?? '#000000',
            $input['icone'] ?? null,
            (int)($input['posicao'] ?? 0),
            !empty($input['destaque']),
            $input['data_inicio'] ?? null,
            $input['data_fim'] ?? null,
        ]);
        $id = $stmt->fetchColumn();
        response(true, ['id' => $id, 'message' => 'Coleção criada']);
    }

    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatório', 400);

        $fields = ['titulo', 'subtitulo', 'descricao', 'slug', 'tipo', 'imagem_url',
                    'cor_fundo', 'cor_texto', 'icone', 'posicao', 'ativo', 'destaque',
                    'data_inicio', 'data_fim'];

        $updates = [];
        $params = [];
        foreach ($fields as $f) {
            if (isset($input[$f])) {
                $updates[] = "$f = ?";
                if ($f === 'ativo' || $f === 'destaque') {
                    $params[] = !empty($input[$f]) ? 'true' : 'false';
                } else {
                    $params[] = $input[$f];
                }
            }
        }

        if (empty($updates)) response(false, null, 'Nada para atualizar', 400);

        $updates[] = "updated_at = NOW()";
        $params[] = $id;
        $stmt = $db->prepare("UPDATE om_market_colecoes SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);
        response(true, ['message' => 'Coleção atualizada']);
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatório', 400);
        $stmt = $db->prepare("DELETE FROM om_market_colecoes WHERE id = ?");
        $stmt->execute([$id]);
        response(true, ['message' => 'Coleção excluída']);
    }

    if ($action === 'add_item') {
        $colecaoId = (int)($input['colecao_id'] ?? 0);
        $itemId = (int)($input['item_id'] ?? 0);
        $tipo = $input['tipo'] ?? 'produto';
        if (!$colecaoId || !$itemId) response(false, null, 'colecao_id e item_id obrigatórios', 400);

        // Get max position
        $stmt = $db->prepare("SELECT COALESCE(MAX(posicao), 0) + 1 FROM om_market_colecao_items WHERE colecao_id = ?");
        $stmt->execute([$colecaoId]);
        $pos = $stmt->fetchColumn();

        $stmt = $db->prepare("
            INSERT INTO om_market_colecao_items (colecao_id, item_id, tipo, posicao) VALUES (?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$colecaoId, $itemId, $tipo, $pos]);
        response(true, ['id' => $stmt->fetchColumn(), 'message' => 'Item adicionado']);
    }

    if ($action === 'remove_item') {
        $itemRefId = (int)($input['item_ref_id'] ?? 0);
        if (!$itemRefId) response(false, null, 'item_ref_id obrigatório', 400);
        $stmt = $db->prepare("DELETE FROM om_market_colecao_items WHERE id = ?");
        $stmt->execute([$itemRefId]);
        response(true, ['message' => 'Item removido']);
    }

    if ($action === 'reorder_items') {
        $colecaoId = (int)($input['colecao_id'] ?? 0);
        $itemIds = $input['item_ids'] ?? []; // ordered array of item_ref_ids
        if (!$colecaoId || empty($itemIds)) response(false, null, 'Dados inválidos', 400);

        $db->beginTransaction();
        foreach ($itemIds as $pos => $refId) {
            $stmt = $db->prepare("UPDATE om_market_colecao_items SET posicao = ? WHERE id = ? AND colecao_id = ?");
            $stmt->execute([$pos, (int)$refId, $colecaoId]);
        }
        $db->commit();
        response(true, ['message' => 'Ordem atualizada']);
    }

    response(false, null, 'Ação inválida', 400);
}
