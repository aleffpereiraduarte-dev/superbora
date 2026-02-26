<?php
/**
 * GET /api/mercado/partner/registration-status.php
 * Returns registration status + documents checklist for the partner
 * Auth: Bearer token
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    // Get partner info
    $stmt = $db->prepare("
        SELECT partner_id, name, owner_name, email, cnpj, status, registration_step,
               razao_social, nome_fantasia, categoria, terms_accepted_at,
               created_at
        FROM om_market_partners WHERE partner_id = ?
    ");
    $stmt->execute([$partner_id]);
    $partner = $stmt->fetch();

    if (!$partner) {
        response(false, null, "Parceiro nao encontrado", 404);
    }

    // Get documents
    $stmt = $db->prepare("
        SELECT id, doc_type, filename, status, review_note, created_at
        FROM om_partner_documents
        WHERE partner_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$partner_id]);
    $docs = $stmt->fetchAll();

    // Build checklist
    $docMap = [];
    foreach ($docs as $doc) {
        // Keep only the latest of each type
        if (!isset($docMap[$doc['doc_type']])) {
            $docMap[$doc['doc_type']] = [
                'id' => (int)$doc['id'],
                'status' => $doc['status'],
                'review_note' => $doc['review_note'],
                'uploaded_at' => $doc['created_at']
            ];
        }
    }

    $checklist = [
        'dados_pessoais' => [
            'label' => 'Dados Pessoais',
            'completed' => !empty($partner['owner_name']) || !empty($partner['name']),
        ],
        'dados_empresa' => [
            'label' => 'Dados da Empresa',
            'completed' => !empty($partner['cnpj']),
        ],
        'endereco' => [
            'label' => 'Endereco',
            'completed' => (int)$partner['registration_step'] >= 3,
        ],
        'selfie_id' => [
            'label' => 'Selfie com Documento',
            'completed' => isset($docMap['selfie_id']),
            'status' => $docMap['selfie_id']['status'] ?? null,
            'review_note' => $docMap['selfie_id']['review_note'] ?? null,
        ],
        'cnpj_card' => [
            'label' => 'Cartao CNPJ',
            'completed' => isset($docMap['cnpj_card']),
            'status' => $docMap['cnpj_card']['status'] ?? null,
            'review_note' => $docMap['cnpj_card']['review_note'] ?? null,
        ],
        'alvara' => [
            'label' => 'Alvara de Funcionamento',
            'completed' => isset($docMap['alvara']),
            'status' => $docMap['alvara']['status'] ?? null,
            'review_note' => $docMap['alvara']['review_note'] ?? null,
            'optional' => true,
        ],
        'termos' => [
            'label' => 'Termos de Uso',
            'completed' => !empty($partner['terms_accepted_at']),
        ],
    ];

    // Overall status
    $partnerStatus = (int)$partner['status'];
    $statusLabel = match($partnerStatus) {
        0 => 'pending',
        1 => 'active',
        2 => 'suspended',
        3 => 'rejected',
        default => 'unknown'
    };

    response(true, [
        'partner_id' => (int)$partner['partner_id'],
        'name' => $partner['nome_fantasia'] ?: $partner['name'],
        'status' => $statusLabel,
        'status_code' => $partnerStatus,
        'registration_step' => (int)$partner['registration_step'],
        'checklist' => $checklist,
        'documents' => $docMap,
        'created_at' => $partner['created_at'],
    ], "Status do cadastro");

} catch (Exception $e) {
    error_log("[registration-status] Erro: " . $e->getMessage());
    response(false, null, "Erro ao consultar status", 500);
}
