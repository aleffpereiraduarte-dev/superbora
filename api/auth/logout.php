<?php
require_once __DIR__ . "/config.php";

try {
    // Invalidar token no servidor se necessário
    response(true, null, "Logout realizado!");
} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
