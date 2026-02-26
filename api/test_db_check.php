<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once '/var/www/html/includes/classes/OmAuth.php';

$db = new PDO("mysql:host=147.93.12.236;dbname=love1;charset=utf8mb4", "love1", "Aleff2009@", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
]);

OmAuth::getInstance()->setDb($db);

// Check token in database
$stmt = $db->prepare("
    SELECT * FROM om_auth_tokens
    WHERE user_type = ? AND user_id = ? AND jti = ?
    AND revoked = 0 AND expires_at > NOW()
");
$stmt->execute(['partner', 100, '4c108f99c3511589b8b3b72703787310']);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

// Full validation
$token = "eyJ0eXBlIjoicGFydG5lciIsInVpZCI6MTAwLCJpYXQiOjE3NzAxNjk4MTUsImV4cCI6MTc3MDc3NDYxNSwianRpIjoiNGMxMDhmOTljMzUxMTU4OWI4YjNiNzI3MDM3ODczMTAiLCJkYXRhIjpbXX0=.8fe45f0164c60dd881232665627d63094a0896ad810d6092af47fe2fdbbcc7f9";
$payload = OmAuth::getInstance()->validateToken($token);

echo json_encode([
    'db_check' => $result ? true : false,
    'db_result' => $result,
    'validate_result' => $payload ? true : false,
    'payload' => $payload,
], JSON_PRETTY_PRINT);
