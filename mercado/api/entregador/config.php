<?php
/**
 * Configuracao base para APIs de entregador do Mercado
 * NOTA: Usa mesma tabela de motoristas do BoraUm (om_boraum_drivers)
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function getDB() {
    return getPDO();
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    return $input ?: $_POST;
}

/**
 * Valida motorista usando tabela om_boraum_drivers
 * Motoristas do BoraUm sao os mesmos entregadores do Mercado
 */
function validateDriver($driver_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_boraum_drivers WHERE driver_id = ?");
    $stmt->execute([$driver_id]);
    return $stmt->fetch();
}

/**
 * Verifica se motorista pode fazer entregas do mercado
 */
function canDeliverMarket($driver) {
    if (!$driver) return false;
    if ($driver['status'] !== 'approved' && $driver['status'] !== 'active') return false;
    if (isset($driver['accepts_market']) && !$driver['accepts_market']) return false;
    return true;
}
