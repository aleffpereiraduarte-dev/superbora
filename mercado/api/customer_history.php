<?php
/**
 * API Historico e Avaliacao do Cliente
 * Actions: get_orders, get_order_details, rate_order, get_ratings
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$action = $_REQUEST["action"] ?? "";
$customer_id = (int)($_REQUEST["customer_id"] ?? 0);

switch ($action) {
    case "get_orders":
        $page = (int)($_GET["page"] ?? 1);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        if (!$customer_id) {
            echo json_encode(["success" => false, "error" => "Customer ID obrigatorio"]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT order_id, order_number, status, total, delivery_fee,
            customer_name, created_at, delivered_at, shopper_rating, delivery_rating
            FROM om_market_orders
            WHERE customer_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?");
        $stmt->execute([$customer_id, $limit, $offset]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Total
        $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM om_market_orders WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)["c"];

        echo json_encode([
            "success" => true,
            "orders" => $orders,
            "total" => $total,
            "page" => $page,
            "pages" => ceil($total / $limit)
        ]);
        break;

    case "get_order_details":
        $order_id = (int)($_GET["order_id"] ?? 0);

        // Dados do pedido
        $stmt = $pdo->prepare("SELECT o.*,
            p.name as partner_name,
            s.name as shopper_name, s.photo as shopper_photo,
            d.name as delivery_name, d.photo as delivery_photo
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
            LEFT JOIN om_market_deliveries d ON o.delivery_id = d.delivery_id
            WHERE o.order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(["success" => false, "error" => "Pedido nao encontrado"]);
            exit;
        }

        // Items
        $stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fotos
        $stmt = $pdo->prepare("SELECT * FROM om_order_photos WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Timeline
        $stmt = $pdo->prepare("SELECT * FROM om_customer_order_history WHERE order_id = ? ORDER BY created_at ASC");
        $stmt->execute([$order_id]);
        $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "order" => $order,
            "items" => $items,
            "photos" => $photos,
            "timeline" => $timeline
        ]);
        break;

    case "rate_order":
        $order_id = (int)($_POST["order_id"] ?? 0);
        $shopper_rating = (int)($_POST["shopper_rating"] ?? 0);
        $delivery_rating = (int)($_POST["delivery_rating"] ?? 0);
        $shopper_tags = $_POST["shopper_tags"] ?? "";
        $delivery_tags = $_POST["delivery_tags"] ?? "";
        $review = trim($_POST["review"] ?? "");

        if (!$order_id || (!$shopper_rating && !$delivery_rating)) {
            echo json_encode(["success" => false, "error" => "Dados incompletos"]);
            exit;
        }

        // Atualizar pedido - build query dynamically
        $updates = ["rated_at = NOW()"];
        $params = [];

        if ($shopper_rating) {
            $updates[] = "shopper_rating = ?";
            $params[] = $shopper_rating;
        }
        if ($delivery_rating) {
            $updates[] = "delivery_rating = ?";
            $params[] = $delivery_rating;
        }
        if ($review) {
            $updates[] = "customer_review = ?";
            $params[] = $review;
        }
        $params[] = $order_id;

        $sql = "UPDATE om_market_orders SET " . implode(", ", $updates) . " WHERE order_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Buscar IDs do shopper/delivery
        $stmt = $pdo->prepare("SELECT shopper_id, delivery_id FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        // Salvar avaliacao detalhada - usar INSERT ... ON CONFLICT para PostgreSQL
        if (isPostgreSQL()) {
            $stmt = $pdo->prepare("INSERT INTO om_order_ratings (order_id, customer_id, shopper_id, delivery_id, shopper_rating, shopper_tags, delivery_rating, delivery_tags)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (order_id) DO UPDATE SET shopper_rating = EXCLUDED.shopper_rating, delivery_rating = EXCLUDED.delivery_rating");
        } else {
            $stmt = $pdo->prepare("INSERT INTO om_order_ratings (order_id, customer_id, shopper_id, delivery_id, shopper_rating, shopper_tags, delivery_rating, delivery_tags)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE shopper_rating=VALUES(shopper_rating), delivery_rating=VALUES(delivery_rating)");
        }
        $stmt->execute([$order_id, $customer_id, $order["shopper_id"], $order["delivery_id"], $shopper_rating, $shopper_tags, $delivery_rating, $delivery_tags]);

        // Atualizar media do shopper/delivery
        if ($shopper_rating && $order["shopper_id"]) {
            $stmt = $pdo->prepare("UPDATE om_market_shoppers SET
                total_ratings = total_ratings + 1,
                avg_rating = (SELECT AVG(shopper_rating) FROM om_market_orders WHERE shopper_id = ? AND shopper_rating IS NOT NULL)
                WHERE shopper_id = ?");
            $stmt->execute([$order["shopper_id"], $order["shopper_id"]]);
        }

        if ($delivery_rating && $order["delivery_id"]) {
            $stmt = $pdo->prepare("UPDATE om_market_deliveries SET
                total_ratings = total_ratings + 1,
                avg_rating = (SELECT AVG(delivery_rating) FROM om_market_orders WHERE delivery_id = ? AND delivery_rating IS NOT NULL)
                WHERE delivery_id = ?");
            $stmt->execute([$order["delivery_id"], $order["delivery_id"]]);
        }

        echo json_encode(["success" => true, "message" => "Avaliacao salva!"]);
        break;

    case "get_rating_tags":
        $type = $_GET["type"] ?? ""; // shopper ou delivery

        $sql = "SELECT * FROM om_rating_tags WHERE is_active = '1'";
        $params = [];

        if ($type) {
            $sql .= " AND tag_type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY sort_order ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "tags" => $tags]);
        break;

    default:
        echo json_encode(["success" => false, "actions" => ["get_orders","get_order_details","rate_order","get_rating_tags"]]);
}
