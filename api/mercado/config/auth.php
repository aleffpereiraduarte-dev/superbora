<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * MIDDLEWARE DE AUTENTICAÇÃO PARA APIS DO MERCADO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Incluir este arquivo em endpoints que requerem autenticação:
 *   require_once __DIR__ . "/config/auth.php";
 *   $auth = requireShopperAuth();  // Retorna dados do shopper autenticado
 */

require_once __DIR__ . "/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmStatusValidator.php";

// Configurar classes com conexão do banco
$db = getDB();
OmAuth::getInstance()->setDb($db);
OmAudit::getInstance()->setDb($db);

/**
 * Requer autenticação de Shopper
 * @param int|null $requestedShopperId ID específico para validar ownership
 * @return array Dados do payload do token
 */
function requireShopperAuth(?int $requestedShopperId = null): array {
    return om_auth()->requireShopper($requestedShopperId);
}

/**
 * Requer autenticação de Motorista
 * @param int|null $requestedMotoristaId ID específico para validar ownership
 * @return array Dados do payload do token
 */
function requireMotoristaAuth(?int $requestedMotoristaId = null): array {
    return om_auth()->requireMotorista($requestedMotoristaId);
}

/**
 * Requer autenticação de Admin
 * @return array Dados do payload do token
 */
function requireAdminAuth(): array {
    return om_auth()->requireAdmin();
}

/**
 * Obtém dados do usuário autenticado (se houver) sem bloquear
 * @return array|null
 */
function getAuthenticatedUser(): ?array {
    $token = om_auth()->getTokenFromRequest();
    if (!$token) return null;
    return om_auth()->validateToken($token);
}

/**
 * Verifica se requisição é autenticada (sem bloquear)
 * @return bool
 */
function isAuthenticated(): bool {
    return getAuthenticatedUser() !== null;
}

/**
 * Helper para log de auditoria
 */
function logAudit(string $action, string $entityType, ?int $entityId = null, $oldData = null, $newData = null, ?string $description = null): bool {
    return om_audit()->log($action, $entityType, $entityId, $oldData, $newData, $description);
}
