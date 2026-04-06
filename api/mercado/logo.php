<?php
$file = dirname(__DIR__, 2) . '/superbora-whatsapp-profile.png';
if (file_exists($file)) {
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($file));
    readfile($file);
} else {
    http_response_code(404);
    echo 'not found';
}
