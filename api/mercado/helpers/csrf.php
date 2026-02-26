<?php
/**
 * Simple CSRF protection for session-based panel endpoints.
 * Checks Origin/Referer header against allowed domains.
 */
function verifyCsrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') return; // GET is safe
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $allowed = ['superbora.com.br', 'localhost'];
    $valid = false;
    foreach ($allowed as $domain) {
        $originHost = parse_url($origin, PHP_URL_HOST) ?? '';
        $refererHost = parse_url($referer, PHP_URL_HOST) ?? '';
        if ($originHost === $domain || $refererHost === $domain) {
            $valid = true;
            break;
        }
    }
    if (!$valid) { // Fail-closed: reject if neither Origin nor Referer matches allowed domains
        http_response_code(403);
        die(json_encode(['error' => 'CSRF validation failed']));
    }
}
