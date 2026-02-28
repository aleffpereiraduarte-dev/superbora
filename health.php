<?php
header("Content-Type: application/json");
header("Cache-Control: no-cache");
$checks = ["php" => true, "db" => false, "redis" => false];
$start = microtime(true);
try {
    $port = 6432;
    $fp = @fsockopen("127.0.0.1", 6432, $errno, $errstr, 1);
    if (!$fp) {
        $fp2 = @fsockopen("127.0.0.1", 5432, $errno, $errstr, 1);
        if ($fp2) { $port = 5432; fclose($fp2); }
    } else {
        fclose($fp);
    }
    $pdo = new PDO("pgsql:host=127.0.0.1;port=$port;dbname=love1", "love1", "Aleff2009@", [PDO::ATTR_TIMEOUT => 3]);
    $pdo->query("SELECT 1");
    $checks["db"] = true;
} catch (Exception $e) {
    $checks["db"] = false;
}
try {
    $redis = new Redis();
    $redis->connect("127.0.0.1", 6379, 2);
    $redis->auth("Aleff2009@Redis");
    $redis->ping();
    $checks["redis"] = true;
} catch (Exception $e) {
    $checks["redis"] = false;
}
$latency = round((microtime(true) - $start) * 1000, 1);
$healthy = $checks["php"] && $checks["db"];
http_response_code($healthy ? 200 : 503);
echo json_encode([
    "status" => $healthy ? "healthy" : "unhealthy",
    "server" => gethostname(),
    "checks" => $checks,
    "latency_ms" => $latency,
    "timestamp" => date("c")
], JSON_UNESCAPED_UNICODE);
