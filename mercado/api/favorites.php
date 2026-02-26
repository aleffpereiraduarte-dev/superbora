<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API DE FAVORITOS - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Ações:
 * - add: Adicionar produto aos favoritos
 * - remove: Remover dos favoritos
 * - toggle: Alternar favorito
 * - get: Retornar favoritos
 * - check: Verificar se produto está nos favoritos
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Conectar ao banco
require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// Sessão
session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar se usuário está logado
$customer_id = $_SESSION['customer_id'] ?? 0;

// Input
$input_raw = file_get_contents('php://input');
$input = json_decode($input_raw, true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? 'get';
$product_id = (int)($input['product_id'] ?? $_GET['product_id'] ?? $_POST['product_id'] ?? 0);

// Para usuários não logados, usar sessão
if (!$customer_id) {
    if (!isset($_SESSION['favorites'])) {
        $_SESSION['favorites'] = [];
    }
    $favorites = &$_SESSION['favorites'];
}

switch ($action) {

    // ========================================================================
    // ADD - Adicionar aos favoritos
    // ========================================================================
    case 'add':
        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Produto não informado']);
            exit;
        }

        if ($customer_id) {
            // Usuário logado - salvar no banco
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO om_market_favorites (customer_id, product_id, partner_id, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$customer_id, $product_id, $partner_id]);
        } else {
            // Visitante - salvar na sessão
            if (!in_array($product_id, $favorites)) {
                $favorites[] = $product_id;
            }
        }

        echo json_encode([
            'success' => true,
            'action' => 'added',
            'product_id' => $product_id,
            'is_favorite' => true
        ]);
        break;

    // ========================================================================
    // REMOVE - Remover dos favoritos
    // ========================================================================
    case 'remove':
        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Produto não informado']);
            exit;
        }

        if ($customer_id) {
            $stmt = $pdo->prepare("DELETE FROM om_market_favorites WHERE customer_id = ? AND product_id = ?");
            $stmt->execute([$customer_id, $product_id]);
        } else {
            $favorites = array_filter($favorites, fn($id) => $id != $product_id);
            $_SESSION['favorites'] = array_values($favorites);
        }

        echo json_encode([
            'success' => true,
            'action' => 'removed',
            'product_id' => $product_id,
            'is_favorite' => false
        ]);
        break;

    // ========================================================================
    // TOGGLE - Alternar favorito
    // ========================================================================
    case 'toggle':
        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Produto não informado']);
            exit;
        }

        $is_favorite = false;

        if ($customer_id) {
            // Verificar se já é favorito
            $stmt = $pdo->prepare("SELECT 1 FROM om_market_favorites WHERE customer_id = ? AND product_id = ?");
            $stmt->execute([$customer_id, $product_id]);
            $exists = $stmt->fetch();

            if ($exists) {
                // Remover
                $stmt = $pdo->prepare("DELETE FROM om_market_favorites WHERE customer_id = ? AND product_id = ?");
                $stmt->execute([$customer_id, $product_id]);
                $is_favorite = false;
            } else {
                // Adicionar
                $stmt = $pdo->prepare("INSERT INTO om_market_favorites (customer_id, product_id, partner_id, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$customer_id, $product_id, $partner_id]);
                $is_favorite = true;
            }
        } else {
            if (in_array($product_id, $favorites)) {
                $favorites = array_filter($favorites, fn($id) => $id != $product_id);
                $_SESSION['favorites'] = array_values($favorites);
                $is_favorite = false;
            } else {
                $favorites[] = $product_id;
                $is_favorite = true;
            }
        }

        echo json_encode([
            'success' => true,
            'action' => $is_favorite ? 'added' : 'removed',
            'product_id' => $product_id,
            'is_favorite' => $is_favorite
        ]);
        break;

    // ========================================================================
    // CHECK - Verificar se é favorito
    // ========================================================================
    case 'check':
        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Produto não informado']);
            exit;
        }

        $is_favorite = false;

        if ($customer_id) {
            $stmt = $pdo->prepare("SELECT 1 FROM om_market_favorites WHERE customer_id = ? AND product_id = ?");
            $stmt->execute([$customer_id, $product_id]);
            $is_favorite = (bool)$stmt->fetch();
        } else {
            $is_favorite = in_array($product_id, $favorites);
        }

        echo json_encode([
            'success' => true,
            'product_id' => $product_id,
            'is_favorite' => $is_favorite
        ]);
        break;

    // ========================================================================
    // GET - Retornar todos os favoritos
    // ========================================================================
    case 'get':
    case 'list':
    default:
        $partner_id = $_SESSION['market_partner_id'] ?? 1;
        $items = [];

        if ($customer_id) {
            // Usuário logado - buscar do banco com detalhes dos produtos
            $stmt = $pdo->prepare("
                SELECT f.product_id, f.created_at,
                       pb.name, pb.brand, pb.image, pb.unit,
                       pp.price, pp.price_promo, pp.stock
                FROM om_market_favorites f
                JOIN om_market_products_base pb ON f.product_id = pb.product_id
                LEFT JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = ?
                WHERE f.customer_id = ?
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$partner_id, $customer_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Visitante - buscar da sessão
            if (!empty($favorites)) {
                $placeholders = str_repeat('?,', count($favorites) - 1) . '?';
                $stmt = $pdo->prepare("
                    SELECT pb.product_id, pb.name, pb.brand, pb.image, pb.unit,
                           pp.price, pp.price_promo, pp.stock
                    FROM om_market_products_base pb
                    LEFT JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = ?
                    WHERE pb.product_id IN ({$placeholders})
                ");
                $params = array_merge([$partner_id], $favorites);
                $stmt->execute($params);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Formatar resposta
        $formatted_items = [];
        foreach ($items as $item) {
            $price = ($item['price_promo'] ?? 0) > 0 && $item['price_promo'] < $item['price']
                ? (float)$item['price_promo']
                : (float)($item['price'] ?? 0);

            $formatted_items[] = [
                'product_id' => (int)$item['product_id'],
                'name' => $item['name'],
                'brand' => $item['brand'],
                'image' => $item['image'],
                'unit' => $item['unit'],
                'price' => (float)($item['price'] ?? 0),
                'price_promo' => (float)($item['price_promo'] ?? 0),
                'final_price' => $price,
                'stock' => (int)($item['stock'] ?? 0),
                'in_stock' => ($item['stock'] ?? 0) > 0
            ];
        }

        echo json_encode([
            'success' => true,
            'count' => count($formatted_items),
            'items' => $formatted_items,
            'is_logged' => $customer_id > 0
        ]);
        break;
}
