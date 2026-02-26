<?php
/**
 * API para verificar status do vendedor
 * Usado para menu dinamico no OpenCart
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

session_name('OCSESSID');
session_start();

$customer_id = $_SESSION['customer_id'] ?? 0;

if (!$customer_id) {
    echo json_encode([
        'logged_in' => false,
        'is_vendor' => false,
        'vendor_status' => null
    ]);
    exit;
}

try {
    $pdo = getPDO();

    // Verificar status no PurpleTree
    $stmt = $pdo->prepare("
        SELECT store_name, store_status, verificacao_status, is_ponto_apoio, ponto_apoio_status
        FROM oc_purpletree_vendor_stores
        WHERE seller_id = ? AND is_removed = 0
    ");
    $stmt->execute([$customer_id]);
    $vendedor = $stmt->fetch();

    // Verificar afiliado
    $stmt = $pdo->prepare("SELECT id, codigo, status FROM om_affiliates WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $afiliado = $stmt->fetch();

    if ($vendedor) {
        $status = 'pendente';
        if ($vendedor['store_status'] == 1) {
            $status = 'aprovado';
        } elseif ($vendedor['verificacao_status'] === 'rejeitado') {
            $status = 'rejeitado';
        }

        echo json_encode([
            'logged_in' => true,
            'is_vendor' => true,
            'vendor_status' => $status,
            'store_name' => $vendedor['store_name'],
            'is_ponto_apoio' => (bool)$vendedor['is_ponto_apoio'],
            'ponto_status' => $vendedor['ponto_apoio_status'],
            'is_affiliate' => (bool)$afiliado,
            'affiliate_status' => $afiliado['status'] ?? null
        ]);
    } else {
        echo json_encode([
            'logged_in' => true,
            'is_vendor' => false,
            'vendor_status' => null,
            'is_affiliate' => (bool)$afiliado,
            'affiliate_status' => $afiliado['status'] ?? null
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'logged_in' => true,
        'is_vendor' => false,
        'vendor_status' => null,
        'error' => 'Erro ao verificar status'
    ]);
}
