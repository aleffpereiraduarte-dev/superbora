<?php
/**
 * Temporary: logs the raw Z-API webhook payload to see exactly what arrives.
 * Forwards to whatsapp-ai.php after logging.
 */
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

// Log to a file we can read
$logEntry = [
    'time' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'phone' => $body['phone'] ?? $body['from'] ?? 'none',
    'fromMe' => $body['fromMe'] ?? null,
    'isGroup' => $body['isGroup'] ?? null,
    'text' => $body['text'] ?? null,
    'body_keys' => array_keys($body ?: []),
    'raw_snippet' => substr($raw, 0, 1000),
];

file_put_contents('/tmp/zapi-webhook-log.json', json_encode($logEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

// Now forward to the real handler
require __DIR__ . '/whatsapp-ai.php';
