<?php
/**
 * API DE SESSÃO
 * Gerencia variáveis de sessão para o frontend
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() === PHP_SESSION_NONE) {
    session_name('OCSESSID');
    session_start();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'set':
        $key = $_GET['key'] ?? $_POST['key'] ?? '';
        $value = $_GET['value'] ?? $_POST['value'] ?? '';

        if ($key) {
            $_SESSION[$key] = $value;
            echo json_encode(['success' => true, 'key' => $key, 'value' => $value]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Key não informada']);
        }
        break;

    case 'get':
        $key = $_GET['key'] ?? $_POST['key'] ?? '';

        if ($key) {
            $value = $_SESSION[$key] ?? null;
            echo json_encode(['success' => true, 'key' => $key, 'value' => $value]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Key não informada']);
        }
        break;

    case 'delete':
    case 'remove':
        $key = $_GET['key'] ?? $_POST['key'] ?? '';

        if ($key && isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
            echo json_encode(['success' => true, 'key' => $key, 'deleted' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Key não encontrada']);
        }
        break;

    case 'clear_location':
        unset($_SESSION['market_partner_id']);
        unset($_SESSION['market_partner_name']);
        unset($_SESSION['market_partner_code']);
        unset($_SESSION['location_checked']);
        unset($_SESSION['cep_cidade']);
        unset($_SESSION['cep_estado']);
        unset($_SESSION['customer_cep']);
        unset($_SESSION['customer_coords']);
        echo json_encode(['success' => true, 'message' => 'Localização limpa']);
        break;

    case 'get_location':
        echo json_encode([
            'success' => true,
            'market_partner_id' => $_SESSION['market_partner_id'] ?? null,
            'market_partner_name' => $_SESSION['market_partner_name'] ?? null,
            'cidade' => $_SESSION['cep_cidade'] ?? null,
            'estado' => $_SESSION['cep_estado'] ?? null,
            'cep' => $_SESSION['customer_cep'] ?? null,
            'location_checked' => isset($_SESSION['location_checked'])
        ]);
        break;

    default:
        echo json_encode([
            'success' => true,
            'session_id' => session_id(),
            'actions' => ['set', 'get', 'delete', 'clear_location', 'get_location']
        ]);
}
