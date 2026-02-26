<?php
/**
 * CORS Helper - SuperBora API
 * Restricts CORS to trusted origins only
 */
$allowed_origins = [
    'https://superbora.com.br',
    'https://www.superbora.com.br',
    'https://mercado.superbora.com.br',
    'http://localhost:3000',
    'http://localhost:8080',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    // For non-browser API clients (mobile app, etc.) — allow without CORS
    // Don't set Access-Control-Allow-Origin at all for unknown origins
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
