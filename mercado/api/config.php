<?php
// Config centralizado do banco
require_once dirname(__DIR__) . '/config/database.php';

if (!function_exists('getDB')) {
    function getDB() {
        return getPDO();
    }
}

function response($success, $data = null, $message = "", $code = 200) {
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    echo json_encode(["success" => $success, "message" => $message, "data" => $data, "timestamp" => date("c")], JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput() {
    $json = file_get_contents("php://input");
    return json_decode($json, true) ?: $_POST ?: $_GET;
}

// So executar verificacao OPTIONS se for acesso direto a uma API
// (evita exit quando config.php e incluido por outras paginas)
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS" &&
    strpos($_SERVER['SCRIPT_FILENAME'] ?? '', '/api/') !== false) {
    http_response_code(200);
    exit;
}
