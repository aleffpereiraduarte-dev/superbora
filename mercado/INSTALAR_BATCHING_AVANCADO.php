<?php
require_once __DIR__ . '/config/database.php';
/**
 * INSTALADOR BATCHING AVANÇADO v1.1
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$BASE = __DIR__;

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die("Erro: " . $e->getMessage());
}

echo '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador Batching Avançado</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: #0f172a; color: #e2e8f0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { font-size: 24px; margin-bottom: 20px; }
        .section { background: #1e293b; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .section h2 { font-size: 16px; margin-bottom: 15px; color: #94a3b8; }
        .log { padding: 8px 12px; margin: 4px 0; border-radius: 6px; font-size: 14px; }
        .log.ok { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .log.error { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .log.info { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .btn { display: inline-block; padding: 12px 24px; border-radius: 10px; font-weight: 600; text-decoration: none; margin-top: 20px; margin-right: 10px; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-success { background: #10b981; color: white; }
    </style>
</head>
<body>
<div class="container">
    <h1>🚀 Instalador Batching Avançado</h1>';

// ════════════════════════════════════════════════════════════════════════════════════════════
// 1. API DE OTIMIZAÇÃO DE ROTA
// ════════════════════════════════════════════════════════════════════════════════════════════

echo '<div class="section"><h2>1️⃣ API de Otimização de Rota</h2>';

$api_route = <<<'APICODE'
<?php
/**
 * API DE OTIMIZAÇÃO DE ROTA
 * Calcula melhor rota para entregas em batch
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["error" => "DB error"]));
}

$input = json_decode(file_get_contents("php://input"), true);
$action = $input["action"] ?? $_GET["action"] ?? "";

function calcDistance($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return 9999;
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function optimizeRoute($start, $destinations) {
    if (empty($destinations)) return ["route" => [], "total_km" => 0];
    
    $route = [];
    $remaining = $destinations;
    $current = $start;
    $totalKm = 0;
    
    while (!empty($remaining)) {
        $nearest = null;
        $nearestDist = PHP_FLOAT_MAX;
        $nearestKey = null;
        
        foreach ($remaining as $key => $dest) {
            $dist = calcDistance($current["lat"], $current["lng"], $dest["lat"], $dest["lng"]);
            if ($dist < $nearestDist) {
                $nearestDist = $dist;
                $nearest = $dest;
                $nearestKey = $key;
            }
        }
        
        if ($nearest) {
            $route[] = $nearest;
            $totalKm += $nearestDist;
            $current = ["lat" => $nearest["lat"], "lng" => $nearest["lng"]];
            unset($remaining[$nearestKey]);
        }
    }
    
    return [
        "route" => $route,
        "total_km" => round($totalKm, 2),
        "estimated_time_min" => round($totalKm * 3, 0)
    ];
}

function isOnTheWay($currentRoute, $newDest, $tolerance = 2) {
    if (empty($currentRoute)) return true;
    $first = $currentRoute[0];
    $last = end($currentRoute);
    $directDist = calcDistance($first["lat"], $first["lng"], $last["lat"], $last["lng"]);
    $viaNewDist = calcDistance($first["lat"], $first["lng"], $newDest["lat"], $newDest["lng"])
                + calcDistance($newDest["lat"], $newDest["lng"], $last["lat"], $last["lng"]);
    return ($viaNewDist - $directDist) <= $tolerance;
}

switch ($action) {
    case "optimize_route":
        $start = $input["start"] ?? null;
        $destinations = $input["destinations"] ?? [];
        if (!$start || empty($destinations)) {
            die(json_encode(["error" => "start and destinations required"]));
        }
        $result = optimizeRoute($start, $destinations);
        echo json_encode([
            "success" => true,
            "optimized_route" => $result["route"],
            "total_distance_km" => $result["total_km"],
            "estimated_time_min" => $result["estimated_time_min"]
        ]);
        break;
        
    case "check_on_way":
        $current_route = $input["current_route"] ?? [];
        $new_dest = $input["new_destination"] ?? null;
        if (!$new_dest) die(json_encode(["error" => "new_destination required"]));
        echo json_encode(["success" => true, "is_on_the_way" => isOnTheWay($current_route, $new_dest)]);
        break;
        
    case "create_batch":
        $driver_id = intval($input["driver_id"] ?? 0);
        $order_ids = $input["order_ids"] ?? [];
        if (!$driver_id || empty($order_ids)) {
            die(json_encode(["error" => "driver_id and order_ids required"]));
        }
        $order_ids = array_slice($order_ids, 0, 5);
        $placeholders = implode(",", array_fill(0, count($order_ids), "?"));
        $stmt = $pdo->prepare("SELECT order_id, shipping_latitude as lat, shipping_longitude as lng, shipping_address as address FROM om_market_orders WHERE order_id IN ({$placeholders})");
        $stmt->execute($order_ids);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($orders)) die(json_encode(["error" => "No orders found"]));
        
        $first = $pdo->query("SELECT p.latitude, p.longitude FROM om_market_orders o JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.order_id = {$order_ids[0]}")->fetch(PDO::FETCH_ASSOC);
        $start = ["lat" => floatval($first["latitude"] ?? -23.5505), "lng" => floatval($first["longitude"] ?? -46.6333)];
        
        $destinations = array_map(function($o) {
            return ["order_id" => $o["order_id"], "lat" => floatval($o["lat"]), "lng" => floatval($o["lng"]), "address" => $o["address"]];
        }, $orders);
        
        $optimized = optimizeRoute($start, $destinations);
        
        $stmt = $pdo->prepare("INSERT INTO om_driver_batches (driver_id, orders_json, route_optimized, total_orders, total_distance_km, estimated_time_min, status, started_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
        $stmt->execute([$driver_id, json_encode($order_ids), json_encode($optimized["route"]), count($order_ids), $optimized["total_km"], $optimized["estimated_time_min"]]);
        $batch_id = $pdo->lastInsertId();
        
        $pdo->exec("UPDATE om_market_orders SET batch_id = {$batch_id} WHERE order_id IN (" . implode(",", $order_ids) . ")");
        $pdo->exec("UPDATE om_market_deliveries SET current_batch_id = {$batch_id} WHERE delivery_id = {$driver_id}");
        
        echo json_encode(["success" => true, "batch_id" => $batch_id, "optimized_route" => $optimized["route"], "total_distance_km" => $optimized["total_km"]]);
        break;
        
    case "get_batch":
        $batch_id = intval($input["batch_id"] ?? $_GET["batch_id"] ?? 0);
        if (!$batch_id) die(json_encode(["error" => "batch_id required"]));
        $batch = $pdo->query("SELECT * FROM om_driver_batches WHERE batch_id = {$batch_id}")->fetch(PDO::FETCH_ASSOC);
        if (!$batch) die(json_encode(["error" => "Batch not found"]));
        echo json_encode(["success" => true, "batch" => $batch]);
        break;
        
    default:
        echo json_encode(["available_actions" => ["optimize_route", "check_on_way", "create_batch", "get_batch"]]);
}
APICODE;

$apiDir = $BASE . '/api';
if (!is_dir($apiDir)) @mkdir($apiDir, 0755, true);

if (file_put_contents($apiDir . '/route_optimizer.php', $api_route)) {
    echo '<div class="log ok">✅ API route_optimizer.php criada</div>';
} else {
    echo '<div class="log error">❌ Erro ao criar route_optimizer.php</div>';
}

echo '</div>';

// ════════════════════════════════════════════════════════════════════════════════════════════
// 2. COMPONENTE DE DESISTÊNCIA
// ════════════════════════════════════════════════════════════════════════════════════════════

echo '<div class="section"><h2>2️⃣ Componente de Desistência</h2>';

$desistencia = <<<'DESIST'
<?php
/**
 * Componente de Desistência - Incluir nas páginas do delivery
 */
?>
<script>
async function desistirPedido(orderId, driverId) {
    if (!confirm("Tem certeza que deseja desistir?\n\nIsso afetará sua pontuação.")) return;
    
    try {
        const response = await fetch("/mercado/api/driver_penalty.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ driver_id: driverId, order_id: orderId, reason: "desistencia" })
        });
        const data = await response.json();
        if (data.success) {
            alert("Pedido liberado.\nPontos perdidos: " + data.points_lost + "\nSeu score: " + data.new_score + "/100");
            location.reload();
        } else {
            alert("Erro: " + (data.error || "Tente novamente"));
        }
    } catch (e) {
        alert("Erro de conexão");
    }
}
</script>
<style>
.btn-desistir { background: #dc2626; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; }
.btn-desistir:hover { background: #b91c1c; }
</style>
DESIST;

$componentsDir = $BASE . '/components';
if (!is_dir($componentsDir)) @mkdir($componentsDir, 0755, true);

if (file_put_contents($componentsDir . '/desistencia-driver.php', $desistencia)) {
    echo '<div class="log ok">✅ Componente desistencia-driver.php criado</div>';
} else {
    echo '<div class="log error">❌ Erro ao criar componente</div>';
}

echo '</div>';

// ════════════════════════════════════════════════════════════════════════════════════════════
// 3. VERIFICAR API PENALTY
// ════════════════════════════════════════════════════════════════════════════════════════════

echo '<div class="section"><h2>3️⃣ Verificar API de Penalidade</h2>';

if (file_exists($BASE . '/api/driver_penalty.php')) {
    echo '<div class="log ok">✅ API driver_penalty.php existe</div>';
} else {
    echo '<div class="log error">❌ API driver_penalty.php não encontrada - rode INSTALAR_DISPATCH_INTELIGENTE.php primeiro</div>';
}

echo '</div>';

// ════════════════════════════════════════════════════════════════════════════════════════════
// RESUMO
// ════════════════════════════════════════════════════════════════════════════════════════════

echo '<div class="section" style="background: linear-gradient(135deg, #065f46, #047857);">
    <h2 style="color: white;">✅ Instalação Concluída!</h2>
    <p style="color: #d1fae5;">APIs de batching e otimização de rota instaladas!</p>
    <br>
    <a href="DIAGNOSTICO_DISPATCH_INTELIGENTE.php" class="btn btn-primary">🔬 Rodar Diagnóstico</a>
    <a href="admin/" class="btn btn-success">📊 Ir para Admin</a>
</div>';

echo '<p style="margin-top: 20px; color: #64748b; font-size: 13px;">
    Instalação em ' . date('d/m/Y H:i:s') . ' - Apague este arquivo após usar!
</p>';

echo '</div></body></html>';
