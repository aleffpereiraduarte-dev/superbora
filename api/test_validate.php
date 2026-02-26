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

$token = "eyJ0eXBlIjoicGFydG5lciIsInVpZCI6MTAwLCJpYXQiOjE3NzAxNjk4MTUsImV4cCI6MTc3MDc3NDYxNSwianRpIjoiNGMxMDhmOTljMzUxMTU4OWI4YjNiNzI3MDM3ODczMTAiLCJkYXRhIjpbXX0=.8fe45f0164c60dd881232665627d63094a0896ad810d6092af47fe2fdbbcc7f9";

// Manually validate
$parts = explode('.', $token);
$payloadBase64 = $parts[0];
$signature = $parts[1];

// Get secret key
$reflection = new ReflectionClass('OmAuth');
$property = $reflection->getProperty('secretKey');
$property->setAccessible(true);
$instance = OmAuth::getInstance();
$secretKey = $property->getValue($instance);

$expectedSignature = hash_hmac('sha256', $payloadBase64, $secretKey);

$payload = json_decode(base64_decode($payloadBase64), true);

echo json_encode([
    'signature_match' => hash_equals($expectedSignature, $signature),
    'expected_sig' => substr($expectedSignature, 0, 20) . '...',
    'actual_sig' => substr($signature, 0, 20) . '...',
    'payload' => $payload,
    'exp_check' => $payload['exp'] > time(),
    'current_time' => time(),
    'token_exp' => $payload['exp'],
], JSON_PRETTY_PRINT);
