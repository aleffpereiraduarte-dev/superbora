<?php
// Debug logger - logs ALL auth requests to a file
$logFile = '/tmp/auth_debug.log';
$data = [
    'time' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    'host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'none',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'none',
    'body' => file_get_contents('php://input'),
    'country' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'unknown',
];
file_put_contents($logFile, json_encode($data) . "\n", FILE_APPEND | LOCK_EX);
