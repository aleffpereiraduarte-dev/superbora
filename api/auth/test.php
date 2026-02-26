<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

echo json_encode([
    'success' => true,
    'message' => 'API is working!',
    'server' => gethostname(),
    'your_ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'],
    'country' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'unknown',
    'host' => $_SERVER['HTTP_HOST'],
    'uri' => $_SERVER['REQUEST_URI'],
    'method' => $_SERVER['REQUEST_METHOD'],
    'timestamp' => date('c'),
]);
