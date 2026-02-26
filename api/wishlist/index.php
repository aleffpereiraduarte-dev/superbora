<?php
/**
 * API de Lista de Desejos (Wishlist) - OneMundo
 *
 * GET    /api/wishlist/ - Listar favoritos do usuário
 * POST   /api/wishlist/ - Adicionar produto aos favoritos
 * DELETE /api/wishlist/?product_id=123 - Remover dos favoritos
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../database.php';

    $pdo = getConnection();

    // Criar tabela se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_wishlist (
        id SERIAL PRIMARY KEY,
        customer_id INT NOT NULL,
        product_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (customer_id, product_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_om_wishlist_customer ON om_wishlist (customer_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_om_wishlist_product ON om_wishlist (product_id)");

    // Obter customer_id (do header, session ou parâmetro)
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $customer_id = (int)($input['customer_id'] ?? $_GET['customer_id'] ?? 0);

    // Para teste, permitir sem autenticação
    if (!$customer_id && isset($_SERVER['HTTP_X_CUSTOMER_ID'])) {
        $customer_id = (int)$_SERVER['HTTP_X_CUSTOMER_ID'];
    }

    // ===== GET - Listar wishlist =====
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$customer_id) {
            // Retornar lista vazia se não logado
            echo json_encode([
                'success' => true,
                'logged_in' => false,
                'total' => 0,
                'items' => []
            ]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT w.id, w.product_id, w.created_at,
                   pd.name, p.price, p.image,
                   (SELECT price FROM " . DB_PREFIX . "product_special
                    WHERE product_id = p.product_id AND date_start <= NOW() AND date_end >= NOW()
                    ORDER BY priority LIMIT 1) as special_price
            FROM om_wishlist w
            JOIN " . DB_PREFIX . "product p ON w.product_id = p.product_id
            JOIN " . DB_PREFIX . "product_description pd ON p.product_id = pd.product_id AND pd.language_id = 1
            WHERE w.customer_id = ? AND p.status = 1
            ORDER BY w.created_at DESC
        ");
        $stmt->execute([$customer_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Formatar items
        $formatted = [];
        foreach ($items as $item) {
            $price = (float)$item['price'];
            $special = $item['special_price'] ? (float)$item['special_price'] : null;

            $formatted[] = [
                'id' => (int)$item['id'],
                'product_id' => (int)$item['product_id'],
                'name' => $item['name'],
                'price' => $price,
                'price_formatted' => 'R$ ' . number_format($price, 2, ',', '.'),
                'special_price' => $special,
                'special_formatted' => $special ? 'R$ ' . number_format($special, 2, ',', '.') : null,
                'discount_percent' => $special ? round((1 - $special / $price) * 100) : 0,
                'image' => $item['image'] ? '/image/' . $item['image'] : '/image/placeholder.png',
                'added_at' => $item['created_at'],
                'url' => '/index.php?route=product/product&product_id=' . $item['product_id']
            ];
        }

        echo json_encode([
            'success' => true,
            'logged_in' => true,
            'total' => count($formatted),
            'items' => $formatted
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== POST - Adicionar à wishlist =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $product_id = (int)($input['product_id'] ?? 0);

        if (!$customer_id) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Faça login para adicionar favoritos']);
            exit;
        }

        if (!$product_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'product_id é obrigatório']);
            exit;
        }

        // Verificar se produto existe
        $stmt = $pdo->prepare("SELECT product_id, status FROM " . DB_PREFIX . "product WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if (!$product || !$product['status']) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Produto não encontrado']);
            exit;
        }

        // Inserir ou ignorar se já existe
        $stmt = $pdo->prepare("INSERT IGNORE INTO om_wishlist (customer_id, product_id) VALUES (?, ?) RETURNING id");
        $stmt->execute([$customer_id, $product_id]);

        $added = $stmt->fetchColumn() > 0;

        // Contar total
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_wishlist WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $total = $stmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'added' => $added,
            'message' => $added ? 'Adicionado aos favoritos!' : 'Já está nos favoritos',
            'total_items' => (int)$total
        ]);
        exit;
    }

    // ===== DELETE - Remover da wishlist =====
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $product_id = (int)($_GET['product_id'] ?? $input['product_id'] ?? 0);

        if (!$customer_id) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        if (!$product_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'product_id é obrigatório']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM om_wishlist WHERE customer_id = ? AND product_id = ?");
        $stmt->execute([$customer_id, $product_id]);

        $removed = $stmt->rowCount() > 0;

        // Contar total restante
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_wishlist WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $total = $stmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'removed' => $removed,
            'message' => $removed ? 'Removido dos favoritos' : 'Não estava nos favoritos',
            'total_items' => (int)$total
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);

} catch (PDOException $e) {
    error_log("Wishlist error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no banco de dados']);
} catch (Exception $e) {
    http_response_code(500);
    error_log("[wishlist] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
