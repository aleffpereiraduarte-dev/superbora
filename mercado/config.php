<?php
/**
 * DELIVERY CONFIG - Gerado automaticamente
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/config/database.php';

if (!function_exists('getDB')) {
    function getDB() {
        return getPDO();
    }
}

function requireLogin() {
    if (!isset($_SESSION["delivery_id"])) {
        header("Location: login.php");
        exit;
    }
}

function getDelivery() {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_market_deliveries WHERE delivery_id = ?");
    $stmt->execute([$_SESSION["delivery_id"]]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function getActiveRoute($delivery_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_delivery_routes WHERE delivery_id = ? AND status = \"active\" LIMIT 1");
    $stmt->execute([$delivery_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getRouteStops($route_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_delivery_route_stops WHERE route_id = ? ORDER BY stop_order ASC");
    $stmt->execute([$route_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPendingDeliveries($delivery_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_items
        FROM om_market_orders o
        WHERE o.status = \"ready\" AND o.route_id IS NULL
        ORDER BY o.order_id ASC
        LIMIT 10
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateDeliveryCode() {
    $palavras = ["BANANA", "LARANJA", "MORANGO", "ABACAXI", "MELANCIA", "MANGA", "UVA", "LIMAO", "COCO", "PERA"];
    return $palavras[array_rand($palavras)] . "-" . rand(100, 999);
}
