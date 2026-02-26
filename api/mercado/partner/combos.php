<?php
/**
 * /api/mercado/partner/combos.php
 * GET - lista combos do parceiro
 * POST - criar/editar combo
 * DELETE ?id=X - remover combo
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
    if (!$payload || $payload['type'] !== 'partner') response(false, null, "Nao autorizado", 401);
    $partnerId = (int)$payload['uid'];

    $method = $_SERVER["REQUEST_METHOD"];

    // GET - Listar combos
    if ($method === "GET") {
        $stmt = $db->prepare("
            SELECT c.*,
                   STRING_AGG(CONCAT(ci.product_id, ':', ci.quantity), ',') AS items_raw
            FROM om_market_combos c
            LEFT JOIN om_market_combo_items ci ON ci.combo_id = c.id
            WHERE c.partner_id = ?
            GROUP BY c.id
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$partnerId]);
        $combos = $stmt->fetchAll();

        // Expandir items com dados dos produtos
        foreach ($combos as &$combo) {
            $combo['items'] = [];
            if ($combo['items_raw']) {
                $rawItems = explode(',', $combo['items_raw']);
                $prodIds = [];
                $qtyMap = [];
                foreach ($rawItems as $ri) {
                    [$pid, $qty] = explode(':', $ri);
                    $prodIds[] = (int)$pid;
                    $qtyMap[(int)$pid] = (int)$qty;
                }
                if ($prodIds) {
                    $ph = implode(',', array_fill(0, count($prodIds), '?'));
                    $stmt2 = $db->prepare("SELECT product_id, name, price, image FROM om_market_products WHERE product_id IN ($ph) AND partner_id = ?");
                    $stmt2->execute(array_merge($prodIds, [$partnerId]));
                    foreach ($stmt2->fetchAll() as $prod) {
                        $combo['items'][] = [
                            'product_id' => (int)$prod['product_id'],
                            'name' => $prod['name'],
                            'price' => (float)$prod['price'],
                            'image' => $prod['image'],
                            'quantity' => $qtyMap[(int)$prod['product_id']] ?? 1
                        ];
                    }
                }
            }
            unset($combo['items_raw']);
        }

        response(true, ['combos' => $combos]);
    }

    // POST - Criar ou editar combo
    if ($method === "POST") {
        $input = getInput();

        $comboId = (int)($input['id'] ?? 0);
        $name = trim(substr($input['name'] ?? '', 0, 200));
        $description = trim($input['description'] ?? '');
        $image = trim($input['image'] ?? '');
        $price = (float)($input['price'] ?? 0);
        $status = isset($input['status']) ? (int)$input['status'] : 1;
        $items = $input['items'] ?? [];

        if (!$name) response(false, null, "Nome do combo obrigatorio", 400);
        if ($price <= 0) response(false, null, "Preco deve ser maior que zero", 400);
        if (empty($items)) response(false, null, "Combo deve ter pelo menos 1 produto", 400);

        // Calcular preco original (soma dos itens)
        $prodIds = array_column($items, 'product_id');
        $ph = implode(',', array_fill(0, count($prodIds), '?'));
        $stmt = $db->prepare("SELECT product_id, price FROM om_market_products WHERE product_id IN ($ph) AND partner_id = ?");
        $params = array_merge($prodIds, [$partnerId]);
        $stmt->execute($params);
        $priceMap = [];
        foreach ($stmt->fetchAll() as $p) $priceMap[$p['product_id']] = (float)$p['price'];

        // Validate ALL product_ids belong to this partner (multi-tenant isolation)
        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            if (!isset($priceMap[$pid])) {
                response(false, null, "Produto #{$pid} nao encontrado ou nao pertence a esta loja", 400);
            }
        }

        $originalPrice = 0;
        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            $qty = max(1, (int)($item['qty'] ?? $item['quantity'] ?? 1));
            $originalPrice += ($priceMap[$pid] ?? 0) * $qty;
        }

        $db->beginTransaction();
        try {
            if ($comboId) {
                // Editar
                $stmt = $db->prepare("
                    UPDATE om_market_combos SET name = ?, description = ?, image = ?, price = ?, original_price = ?, status = ?
                    WHERE id = ? AND partner_id = ?
                ");
                $stmt->execute([$name, $description, $image, $price, $originalPrice, $status, $comboId, $partnerId]);

                // Remover items antigos
                $db->prepare("DELETE FROM om_market_combo_items WHERE combo_id = ?")->execute([$comboId]);
            } else {
                // Criar
                $stmt = $db->prepare("
                    INSERT INTO om_market_combos (partner_id, name, description, image, price, original_price, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    RETURNING id
                ");
                $stmt->execute([$partnerId, $name, $description, $image, $price, $originalPrice, $status]);
                $comboId = (int)$stmt->fetchColumn();
            }

            // Inserir items
            $stmtItem = $db->prepare("INSERT INTO om_market_combo_items (combo_id, product_id, quantity) VALUES (?, ?, ?)");
            foreach ($items as $item) {
                $stmtItem->execute([
                    $comboId,
                    (int)$item['product_id'],
                    max(1, (int)($item['qty'] ?? $item['quantity'] ?? 1))
                ]);
            }

            $db->commit();
            response(true, ['id' => $comboId], "Combo salvo com sucesso");

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    // DELETE - Soft-delete combo (preserve for audit trail and order references)
    if ($method === "DELETE") {
        $comboId = (int)($_GET['id'] ?? 0);
        if (!$comboId) response(false, null, "ID do combo obrigatorio", 400);

        $stmt = $db->prepare("UPDATE om_market_combos SET status = 0 WHERE id = ? AND partner_id = ?");
        $stmt->execute([$comboId, $partnerId]);

        if ($stmt->rowCount() === 0) {
            response(false, null, "Combo nao encontrado", 404);
        }
        response(true, null, "Combo removido");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/combos] Erro: " . $e->getMessage());
    response(false, null, "Erro ao gerenciar combos", 500);
}
