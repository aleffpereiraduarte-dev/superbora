<?php
header("Content-Type: application/json");
header("Cache-Control: no-cache");

// DEV/STAGING SERVER - Return unhealthy to Cloudflare LB
// This server should NOT receive production traffic
// Remove from Cloudflare pool and restore this file after

http_response_code(503);
echo json_encode([
    "status" => "unhealthy",
    "server" => gethostname(),
    "role" => "dev-staging",
    "message" => "Server converted to dev/staging - not for production traffic",
    "timestamp" => date("c")
], JSON_UNESCAPED_UNICODE);
