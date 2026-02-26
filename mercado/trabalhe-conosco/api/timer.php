<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Gerenciamento do Timer de 60 minutos
 * 
 * GET  /api/timer.php?order_id=X     - Verificar status do timer
 * POST /api/timer.php                 - Ações: start, pause, resume, extend, complete
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");

session_start();

$workerId = $_SESSION["worker_id"] ?? null;

try {
    $db = new PDO("mysql:host=localhost;dbname=love1;charset=utf8mb4",
        "love1",
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit(json_encode(["success" => false, "error" => "DB error"]));
}

// GET - Verificar status do timer
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $orderId = $_GET["order_id"] ?? null;
    
    if (!$orderId) {
        exit(json_encode(["success" => false, "error" => "order_id obrigatório"]));
    }
    
    $stmt = $db->prepare("
        SELECT order_id, timer_started, timer_duration, timer_expires, 
               timer_paused, timer_paused_at, timer_extra_time,
               shopping_started, shopping_completed
        FROM om_market_orders 
        WHERE order_id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        exit(json_encode(["success" => false, "error" => "Pedido não encontrado"]));
    }
    
    // Calcular tempo restante
    $timeRemaining = 0;
    $status = "not_started";
    
    if ($order["timer_started"]) {
        if ($order["shopping_completed"]) {
            $status = "completed";
            $timeRemaining = 0;
        } elseif ($order["timer_paused"]) {
            $status = "paused";
            $pausedAt = strtotime($order["timer_paused_at"]);
            $started = strtotime($order["timer_started"]);
            $elapsed = $pausedAt - $started;
            $totalTime = $order["timer_duration"] + $order["timer_extra_time"];
            $timeRemaining = max(0, $totalTime - $elapsed);
        } else {
            $started = strtotime($order["timer_started"]);
            $totalTime = $order["timer_duration"] + $order["timer_extra_time"];
            $elapsed = time() - $started;
            $timeRemaining = max(0, $totalTime - $elapsed);
            
            if ($timeRemaining <= 0) {
                $status = "expired";
            } else {
                $status = "running";
            }
        }
    }
    
    echo json_encode([
        "success" => true,
        "order_id" => $orderId,
        "status" => $status,
        "time_remaining" => $timeRemaining,
        "time_remaining_formatted" => sprintf("%02d:%02d", floor($timeRemaining/60), $timeRemaining%60),
        "timer_started" => $order["timer_started"],
        "timer_duration" => $order["timer_duration"],
        "timer_extra_time" => $order["timer_extra_time"],
        "is_paused" => (bool)$order["timer_paused"],
        "percentage" => $order["timer_duration"] > 0 
            ? round((($order["timer_duration"] + $order["timer_extra_time"] - $timeRemaining) / ($order["timer_duration"] + $order["timer_extra_time"])) * 100, 1) 
            : 0
    ]);
    exit;
}

// POST - Ações do timer
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!$workerId) {
        http_response_code(401);
        exit(json_encode(["success" => false, "error" => "Não autenticado"]));
    }
    
    $input = json_decode(file_get_contents("php://input"), true);
    $orderId = $input["order_id"] ?? $_POST["order_id"] ?? null;
    $action = $input["action"] ?? $_POST["action"] ?? null;
    
    if (!$orderId || !$action) {
        exit(json_encode(["success" => false, "error" => "order_id e action obrigatórios"]));
    }
    
    switch ($action) {
        case "start":
            // Iniciar timer
            $duration = $input["duration"] ?? 3600; // 60 min padrão
            $expires = date("Y-m-d H:i:s", time() + $duration);
            
            $db->prepare("
                UPDATE om_market_orders 
                SET timer_started = NOW(), 
                    timer_duration = ?,
                    timer_expires = ?,
                    shopping_started = NOW()
                WHERE order_id = ?
            ")->execute([$duration, $expires, $orderId]);
            
            // Log
            $db->prepare("INSERT INTO om_timer_log (order_id, worker_id, action) VALUES (?, ?, 'started')")
               ->execute([$orderId, $workerId]);
            
            echo json_encode(["success" => true, "message" => "Timer iniciado", "expires" => $expires]);
            break;
            
        case "pause":
            // Pausar timer
            $db->prepare("
                UPDATE om_market_orders 
                SET timer_paused = 1, timer_paused_at = NOW()
                WHERE order_id = ?
            ")->execute([$orderId]);
            
            $reason = $input["reason"] ?? "Pausado pelo shopper";
            $db->prepare("INSERT INTO om_timer_log (order_id, worker_id, action, reason) VALUES (?, ?, 'paused', ?)")
               ->execute([$orderId, $workerId, $reason]);
            
            echo json_encode(["success" => true, "message" => "Timer pausado"]);
            break;
            
        case "resume":
            // Retomar timer - adiciona o tempo pausado
            $stmt = $db->prepare("SELECT timer_paused_at FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if ($order && $order["timer_paused_at"]) {
                $pausedSeconds = time() - strtotime($order["timer_paused_at"]);
                
                $db->prepare("
                    UPDATE om_market_orders 
                    SET timer_paused = 0, 
                        timer_paused_at = NULL,
                        timer_extra_time = timer_extra_time + ?
                    WHERE order_id = ?
                ")->execute([$pausedSeconds, $orderId]);
            }
            
            $db->prepare("INSERT INTO om_timer_log (order_id, worker_id, action) VALUES (?, ?, 'resumed')")
               ->execute([$orderId, $workerId]);
            
            echo json_encode(["success" => true, "message" => "Timer retomado"]);
            break;
            
        case "extend":
            // Adicionar tempo extra
            $extraMinutes = $input["extra_minutes"] ?? 15;
            $extraSeconds = $extraMinutes * 60;
            $reason = $input["reason"] ?? "Tempo extra solicitado";
            
            $db->prepare("
                UPDATE om_market_orders 
                SET timer_extra_time = timer_extra_time + ?
                WHERE order_id = ?
            ")->execute([$extraSeconds, $orderId]);
            
            $db->prepare("INSERT INTO om_timer_log (order_id, worker_id, action, extra_seconds, reason) VALUES (?, ?, 'extended', ?, ?)")
               ->execute([$orderId, $workerId, $extraSeconds, $reason]);
            
            echo json_encode(["success" => true, "message" => "Tempo extra adicionado: {$extraMinutes} minutos"]);
            break;
            
        case "complete":
            // Marcar compras como completas
            $db->prepare("
                UPDATE om_market_orders 
                SET shopping_completed = NOW(),
                    order_status_id = 3
                WHERE order_id = ?
            ")->execute([$orderId]);
            
            $db->prepare("INSERT INTO om_timer_log (order_id, worker_id, action) VALUES (?, ?, 'completed')")
               ->execute([$orderId, $workerId]);
            
            echo json_encode(["success" => true, "message" => "Compras finalizadas!"]);
            break;
            
        default:
            echo json_encode(["success" => false, "error" => "Ação inválida"]);
    }
    exit;
}

http_response_code(405);
echo json_encode(["success" => false, "error" => "Método não permitido"]);
