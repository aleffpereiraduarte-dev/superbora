<?php
/**
 * POST /api/mercado/customer/export-data.php
 * LGPD data export - queues an export of customer data
 * Returns a download URL when ready
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    // Check if there's a recent pending export (prevent spam)
    $stmt = $db->prepare("
        SELECT request_id AS id, requested_at AS created_at FROM om_data_export_requests
        WHERE customer_id = ? AND status = 'pending' AND requested_at > NOW() - INTERVAL '1 hour'
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $existing = $stmt->fetch();

    if ($existing) {
        response(true, [
            'status' => 'pending',
            'message' => 'Uma exportacao ja esta em andamento. Voce recebera uma notificacao quando estiver pronta.',
            'requested_at' => $existing['created_at'],
        ], 'Exportacao ja solicitada');
    }

    // Queue export request
    $stmt = $db->prepare("
        INSERT INTO om_data_export_requests (customer_id, status, requested_at)
        VALUES (?, 'pending', NOW())
        RETURNING request_id AS id
    ");
    $stmt->execute([$customerId]);
    $exportId = $stmt->fetchColumn();

    if (!$exportId) {
        response(true, [
            'status' => 'pending',
            'message' => 'Exportacao ja solicitada. Aguarde.',
        ], 'Exportacao em andamento');
    }

    response(true, [
        'status' => 'queued',
        'export_id' => (int)$exportId,
        'message' => 'Sua solicitacao de exportacao de dados foi recebida. Voce sera notificado quando o arquivo estiver pronto para download.',
    ], 'Exportacao de dados solicitada');

} catch (Exception $e) {
    error_log("[customer/export-data] Erro: " . $e->getMessage());
    response(false, null, "Erro ao solicitar exportacao de dados", 500);
}
