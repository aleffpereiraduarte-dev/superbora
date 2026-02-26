<?php
/**
 * GET/POST/DELETE /api/mercado/customer/favorites.php
 * GET    - Lista colecoes de favoritos
 * POST   - Criar colecao ou adicionar item
 * DELETE - Remover colecao ou item
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
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Nao autorizado", 401);
    }

    $customerId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Listar favoritos
    if ($method === 'GET') {
        $collectionId = (int)($_GET['collection_id'] ?? 0);

        if ($collectionId) {
            // Listar itens de uma colecao especifica
            $stmt = $db->prepare("
                SELECT fi.id, fi.favorite_id, fi.customer_id, fi.item_type, fi.item_id, fi.notes, fi.created_at,
                    CASE
                        WHEN fi.item_type = 'product' THEN p.name
                        WHEN fi.item_type = 'store' THEN pa.trade_name
                        WHEN fi.item_type = 'combo' THEN c.name
                    END as item_name,
                    CASE
                        WHEN fi.item_type = 'product' THEN p.image
                        WHEN fi.item_type = 'store' THEN pa.logo
                        WHEN fi.item_type = 'combo' THEN c.image
                    END as item_image,
                    CASE
                        WHEN fi.item_type = 'product' THEN p.price
                        ELSE NULL
                    END as item_price
                FROM om_favorite_items fi
                LEFT JOIN om_market_products p ON fi.item_type = 'product' AND fi.item_id = p.product_id
                LEFT JOIN om_market_partners pa ON fi.item_type = 'store' AND fi.item_id = pa.partner_id
                LEFT JOIN om_market_combos c ON fi.item_type = 'combo' AND fi.item_id = c.id
                WHERE fi.favorite_id = ? AND fi.customer_id = ?
                ORDER BY fi.created_at DESC
            ");
            $stmt->execute([$collectionId, $customerId]);
            $items = $stmt->fetchAll();

            response(true, ['items' => $items]);
        }

        // Listar todas as colecoes
        $stmt = $db->prepare("
            SELECT id, name, description, icon, color, is_default, item_count, created_at
            FROM om_favorites
            WHERE customer_id = ?
            ORDER BY is_default DESC, created_at ASC
        ");
        $stmt->execute([$customerId]);
        $collections = $stmt->fetchAll();

        // Se nao tem nenhuma, criar a padrao
        if (empty($collections)) {
            $db->prepare("
                INSERT INTO om_favorites (customer_id, name, is_default)
                VALUES (?, 'Favoritos', 1)
            ")->execute([$customerId]);

            $collections = [[
                'id' => (int)$db->lastInsertId(),
                'name' => 'Favoritos',
                'description' => null,
                'icon' => 'heart',
                'color' => '#ef4444',
                'is_default' => 1,
                'item_count' => 0
            ]];
        }

        response(true, ['collections' => $collections]);
    }

    // POST - Criar colecao ou adicionar item
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'add_item';

        if ($action === 'create_collection') {
            $name = strip_tags(trim($input['name'] ?? ''));
            if (empty($name)) {
                response(false, null, "Nome da colecao obrigatorio", 400);
            }

            $stmt = $db->prepare("
                INSERT INTO om_favorites (customer_id, name, description, icon, color)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $customerId,
                $name,
                isset($input['description']) ? strip_tags($input['description']) : null,
                $input['icon'] ?? 'heart',
                $input['color'] ?? '#ef4444'
            ]);

            response(true, [
                'collection_id' => (int)$db->lastInsertId(),
                'message' => 'Colecao criada!'
            ]);
        }

        // Adicionar item
        $collectionId = (int)($input['collection_id'] ?? 0);
        $itemType = $input['item_type'] ?? '';
        $itemId = (int)($input['item_id'] ?? 0);

        if (!$itemType || !$itemId) {
            response(false, null, "Tipo e ID do item obrigatorios", 400);
        }

        if (!in_array($itemType, ['product', 'store', 'combo'])) {
            response(false, null, "Tipo invalido", 400);
        }

        // Se nao informou colecao, usar a padrao
        if (!$collectionId) {
            $stmt = $db->prepare("
                SELECT id FROM om_favorites
                WHERE customer_id = ? AND is_default = 1
            ");
            $stmt->execute([$customerId]);
            $default = $stmt->fetch();

            if (!$default) {
                $db->prepare("
                    INSERT INTO om_favorites (customer_id, name, is_default)
                    VALUES (?, 'Favoritos', 1)
                ")->execute([$customerId]);
                $collectionId = (int)$db->lastInsertId();
            } else {
                $collectionId = (int)$default['id'];
            }
        } else {
            // Verify collection belongs to authenticated customer (IDOR protection)
            $stmt = $db->prepare("
                SELECT id FROM om_favorites
                WHERE id = ? AND customer_id = ?
            ");
            $stmt->execute([$collectionId, $customerId]);
            if (!$stmt->fetch()) {
                response(false, null, "Colecao nao encontrada", 404);
            }
        }

        // Inserir dentro de transação para evitar race condition
        $notes = isset($input['notes']) ? strip_tags($input['notes']) : null;
        $db->beginTransaction();
        try {
            // Lock na colecao para serializar inserts concorrentes
            $stmt = $db->prepare("SELECT id FROM om_favorites WHERE id = ? FOR UPDATE");
            $stmt->execute([$collectionId]);

            // Re-check dentro da transação
            $stmt = $db->prepare("
                SELECT id FROM om_favorite_items
                WHERE favorite_id = ? AND item_type = ? AND item_id = ?
            ");
            $stmt->execute([$collectionId, $itemType, $itemId]);
            if ($stmt->fetch()) {
                $db->commit();
                response(false, null, "Item ja esta nos favoritos", 400);
            }

            $stmt = $db->prepare("
                INSERT INTO om_favorite_items (favorite_id, customer_id, item_type, item_id, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$collectionId, $customerId, $itemType, $itemId, $notes]);

            // Atualizar contador
            $db->prepare("
                UPDATE om_favorites SET item_count = item_count + 1
                WHERE id = ?
            ")->execute([$collectionId]);

            $db->commit();
        } catch (Exception $txEx) {
            $db->rollBack();
            throw $txEx;
        }

        response(true, ['message' => 'Adicionado aos favoritos!']);
    }

    // DELETE - Remover
    if ($method === 'DELETE') {
        $input = getInput();
        $collectionId = (int)($input['collection_id'] ?? $_GET['collection_id'] ?? 0);
        $itemId = (int)($input['item_id'] ?? $_GET['item_id'] ?? 0);

        if ($itemId) {
            // Remover item especifico
            $stmt = $db->prepare("
                DELETE FROM om_favorite_items
                WHERE id = ? AND customer_id = ?
            ");
            $stmt->execute([$itemId, $customerId]);

            if ($stmt->rowCount() > 0 && $collectionId) {
                $db->prepare("
                    UPDATE om_favorites SET item_count = GREATEST(0, item_count - 1)
                    WHERE id = ? AND customer_id = ?
                ")->execute([$collectionId, $customerId]);
            }

            response(true, ['message' => 'Removido dos favoritos']);
        }

        if ($collectionId) {
            // Verificar se nao e a padrao
            $stmt = $db->prepare("
                SELECT is_default FROM om_favorites
                WHERE id = ? AND customer_id = ?
            ");
            $stmt->execute([$collectionId, $customerId]);
            $collection = $stmt->fetch();

            if ($collection && $collection['is_default']) {
                response(false, null, "Nao pode remover a colecao padrao", 400);
            }

            // Remover colecao (cascade deleta os itens)
            $db->prepare("
                DELETE FROM om_favorites
                WHERE id = ? AND customer_id = ?
            ")->execute([$collectionId, $customerId]);

            response(true, ['message' => 'Colecao removida']);
        }

        response(false, null, "ID obrigatorio", 400);
    }

} catch (Exception $e) {
    error_log("[customer/favorites] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar favoritos", 500);
}
