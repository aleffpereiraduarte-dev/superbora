<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 📊 API DE RELATÓRIOS
 */
header("Content-Type: application/json");

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(array("success" => false, "error" => "DB Error")));
}

$action = isset($_GET["action"]) ? $_GET["action"] : "";
$period = isset($_GET["period"]) ? $_GET["period"] : "today";
$partner_id = isset($_GET["partner_id"]) ? intval($_GET["partner_id"]) : null;

// Definir datas baseado no período
switch ($period) {
    case "today":
        $date_start = date("Y-m-d 00:00:00");
        $date_end = date("Y-m-d 23:59:59");
        break;
    case "yesterday":
        $date_start = date("Y-m-d 00:00:00", strtotime("-1 day"));
        $date_end = date("Y-m-d 23:59:59", strtotime("-1 day"));
        break;
    case "week":
        $date_start = date("Y-m-d 00:00:00", strtotime("-7 days"));
        $date_end = date("Y-m-d 23:59:59");
        break;
    case "month":
        $date_start = date("Y-m-01 00:00:00");
        $date_end = date("Y-m-d 23:59:59");
        break;
    case "year":
        $date_start = date("Y-01-01 00:00:00");
        $date_end = date("Y-m-d 23:59:59");
        break;
    default:
        $date_start = date("Y-m-d 00:00:00");
        $date_end = date("Y-m-d 23:59:59");
}

$partner_filter = $partner_id ? "AND partner_id = $partner_id" : "";

switch ($action) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // DASHBOARD GERAL
    // ══════════════════════════════════════════════════════════════════════════
    case "dashboard":
        $data = array();
        
        // Pedidos do período
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status IN (\"delivered\", \"completed\") THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN status = \"cancelled\" THEN 1 ELSE 0 END) as cancelled_orders,
                SUM(CASE WHEN status IN (\"pending\", \"confirmed\", \"preparing\", \"ready\", \"delivering\") THEN 1 ELSE 0 END) as active_orders,
                COALESCE(SUM(CASE WHEN status IN (\"delivered\", \"completed\") THEN total ELSE 0 END), 0) as revenue,
                COALESCE(AVG(CASE WHEN status IN (\"delivered\", \"completed\") THEN total ELSE NULL END), 0) as avg_ticket
            FROM om_market_orders 
            WHERE created_at BETWEEN \"$date_start\" AND \"$date_end\" $partner_filter
        ");
        $data["orders"] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Comparação com período anterior
        $prev_start = date("Y-m-d H:i:s", strtotime($date_start) - (strtotime($date_end) - strtotime($date_start)));
        $prev_end = date("Y-m-d H:i:s", strtotime($date_start) - 1);
        
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_orders,
                COALESCE(SUM(CASE WHEN status IN (\"delivered\", \"completed\") THEN total ELSE 0 END), 0) as revenue
            FROM om_market_orders 
            WHERE created_at BETWEEN \"$prev_start\" AND \"$prev_end\" $partner_filter
        ");
        $data["previous"] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calcular variações
        $prev_orders = intval($data["previous"]["total_orders"]) ?: 1;
        $prev_revenue = floatval($data["previous"]["revenue"]) ?: 1;
        $data["variation"] = array(
            "orders" => round((($data["orders"]["total_orders"] - $prev_orders) / $prev_orders) * 100, 1),
            "revenue" => round((($data["orders"]["revenue"] - $prev_revenue) / $prev_revenue) * 100, 1)
        );
        
        // Equipe online
        $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = '1' OR status = \"active\" THEN 1 ELSE 0 END) as online FROM om_market_shoppers");
        $data["shoppers"] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN is_online = 1 THEN 1 ELSE 0 END) as online FROM om_market_deliveries");
        $data["deliveries"] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Mercados
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM om_market_partners WHERE status = '1'");
        $data["partners"] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "data" => $data, "period" => $period));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // GRÁFICO DE PEDIDOS POR HORA
    // ══════════════════════════════════════════════════════════════════════════
    case "orders_by_hour":
        $stmt = $pdo->query("
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as orders,
                SUM(total) as revenue
            FROM om_market_orders 
            WHERE created_at BETWEEN \"$date_start\" AND \"$date_end\" $partner_filter
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Preencher horas vazias
        $hours = array();
        for ($i = 0; $i < 24; $i++) {
            $hours[$i] = array("hour" => $i, "orders" => 0, "revenue" => 0);
        }
        foreach ($data as $row) {
            $hours[$row["hour"]] = $row;
        }
        
        echo json_encode(array("success" => true, "data" => array_values($hours)));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // GRÁFICO DE PEDIDOS POR DIA
    // ══════════════════════════════════════════════════════════════════════════
    case "orders_by_day":
        $stmt = $pdo->query("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as orders,
                SUM(total) as revenue
            FROM om_market_orders 
            WHERE created_at BETWEEN \"$date_start\" AND \"$date_end\" $partner_filter
            GROUP BY DATE(created_at)
            ORDER BY date
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "data" => $data));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // TOP PRODUTOS
    // ══════════════════════════════════════════════════════════════════════════
    case "top_products":
        $limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 10;
        
        $stmt = $pdo->query("
            SELECT 
                p.name,
                COUNT(*) as quantity,
                SUM(p.total) as revenue
            FROM om_market_order_items p
            JOIN om_market_orders o ON p.order_id = o.order_id
            WHERE o.created_at BETWEEN \"$date_start\" AND \"$date_end\" 
                  AND o.status IN (\"delivered\", \"completed\")
                  $partner_filter
            GROUP BY p.name
            ORDER BY quantity DESC
            LIMIT $limit
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "data" => $data));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // RANKING SHOPPERS
    // ══════════════════════════════════════════════════════════════════════════
    case "top_shoppers":
        $stmt = $pdo->query("
            SELECT 
                s.shopper_id,
                s.name,
                s.avg_rating,
                COUNT(o.order_id) as orders,
                SUM(o.total) as revenue
            FROM om_market_shoppers s
            LEFT JOIN om_market_orders o ON s.shopper_id = o.shopper_id 
                AND o.created_at BETWEEN \"$date_start\" AND \"$date_end\"
                AND o.status IN (\"delivered\", \"completed\")
            GROUP BY s.shopper_id
            ORDER BY orders DESC
            LIMIT 10
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "data" => $data));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // RANKING DELIVERIES
    // ══════════════════════════════════════════════════════════════════════════
    case "top_deliveries":
        $stmt = $pdo->query("
            SELECT 
                d.delivery_id,
                d.name,
                d.avg_rating,
                d.vehicle_type,
                COUNT(o.order_id) as orders
            FROM om_market_deliveries d
            LEFT JOIN om_market_orders o ON d.delivery_id = o.delivery_id 
                AND o.created_at BETWEEN \"$date_start\" AND \"$date_end\"
                AND o.status IN (\"delivered\", \"completed\")
            GROUP BY d.delivery_id
            ORDER BY orders DESC
            LIMIT 10
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "data" => $data));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // MÉTRICAS DE TEMPO
    // ══════════════════════════════════════════════════════════════════════════
    case "time_metrics":
        $stmt = $pdo->query("
            SELECT 
                AVG(TIMESTAMPDIFF(MINUTE, created_at, shopper_started_at)) as avg_acceptance,
                AVG(TIMESTAMPDIFF(MINUTE, shopper_started_at, shopper_finished_at)) as avg_shopping,
                AVG(TIMESTAMPDIFF(MINUTE, shopper_finished_at, delivery_picked_at)) as avg_handoff,
                AVG(TIMESTAMPDIFF(MINUTE, delivery_picked_at, delivered_at)) as avg_delivery,
                AVG(TIMESTAMPDIFF(MINUTE, created_at, delivered_at)) as avg_total
            FROM om_market_orders 
            WHERE created_at BETWEEN \"$date_start\" AND \"$date_end\"
                  AND status IN (\"delivered\", \"completed\")
                  AND delivered_at IS NOT NULL
                  $partner_filter
        ");
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "data" => $data));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // CANCELAMENTOS
    // ══════════════════════════════════════════════════════════════════════════
    case "cancellations":
        $stmt = $pdo->query("
            SELECT 
                reason,
                COUNT(*) as count
            FROM om_order_cancellations
            WHERE created_at BETWEEN \"$date_start\" AND \"$date_end\"
            GROUP BY reason
            ORDER BY count DESC
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "data" => $data));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // AVALIAÇÕES
    // ══════════════════════════════════════════════════════════════════════════
    case "ratings":
        $stmt = $pdo->query("
            SELECT 
                AVG(overall_rating) as avg_overall,
                AVG(shopper_rating) as avg_shopper,
                AVG(delivery_rating) as avg_delivery,
                COUNT(*) as total_ratings
            FROM om_order_ratings
            WHERE created_at BETWEEN \"$date_start\" AND \"$date_end\"
        ");
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Distribuição de notas
        $stmt = $pdo->query("
            SELECT overall_rating as rating, COUNT(*) as count
            FROM om_order_ratings
            WHERE created_at BETWEEN \"$date_start\" AND \"$date_end\"
            GROUP BY overall_rating
            ORDER BY overall_rating
        ");
        $data["distribution"] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "data" => $data));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}
