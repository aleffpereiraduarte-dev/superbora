<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * MIDDLEWARE DE AUTENTICAÇÃO PARA APIS FINANCEIRAS E ADMIN
 * ══════════════════════════════════════════════════════════════════════════════
 */

require_once dirname(__DIR__, 2) . "/mercado/config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmStatusValidator.php";

// Configurar classes com conexão do banco
$db = getDB();
OmAuth::getInstance()->setDb($db);
OmAudit::getInstance()->setDb($db);

/**
 * Requer autenticação de Admin para APIs administrativas
 * Admin = funcionário contratado pelo RH OU super admin (RH)
 * CRÍTICO: Todas as APIs admin DEVEM chamar esta função
 */
function requireAdminAuth(): array {
    return om_auth()->requireAdmin();
}

/**
 * Requer autenticação de RH (super admin)
 * Apenas RH pode aprovar shoppers, contratar admins, cadastrar mercados
 */
function requireRHAuth(): array {
    return om_auth()->requireRH();
}

/**
 * Requer autenticação de Partner (mercado)
 * Mercado precisa ter sido cadastrado e aprovado pelo RH
 */
function requirePartnerAuth(?int $partnerId = null): array {
    return om_auth()->requirePartner($partnerId);
}

/**
 * Requer autenticação de Shopper (para APIs de saldo/saque do shopper)
 */
function requireShopperAuth(?int $shopperId = null): array {
    return om_auth()->requireShopper($shopperId);
}

/**
 * Requer autenticação de Motorista (para APIs de saldo/saque do motorista)
 */
function requireMotoristaAuth(?int $motoristaId = null): array {
    return om_auth()->requireMotorista($motoristaId);
}

/**
 * Requer autenticação de worker (shopper OU motorista)
 */
function requireWorkerAuth(string $tipo, int $id): array {
    if ($tipo === 'shopper') {
        return requireShopperAuth($id);
    } elseif ($tipo === 'motorista') {
        return requireMotoristaAuth($id);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tipo de usuário inválido']);
        exit;
    }
}

/**
 * Helper para log de auditoria com contexto financeiro
 */
function logFinanceiroAudit(string $action, string $entityType, ?int $entityId = null, $oldData = null, $newData = null, ?string $description = null): bool {
    return om_audit()->log($action, $entityType, $entityId, $oldData, $newData, $description);
}

/**
 * Valida transição de status de saque
 */
function validateSaqueTransition(string $currentStatus, string $newStatus): void {
    om_status()->validateSaqueTransition($currentStatus, $newStatus);
}
